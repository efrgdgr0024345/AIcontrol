<?php
declare(strict_types=1);

namespace AIcontrol;

use RuntimeException;
use Throwable;
require_once __DIR__.'/SystemsAgent.php';
require_once __DIR__.'/SystemsView.php';
require_once __DIR__.'/SystemEnrollment.php';
require_once __DIR__.'/SystemTerminal.php';
require_once __DIR__.'/SystemGuard.php';
require_once __DIR__.'/SystemPlaybooks.php';
require_once __DIR__.'/ResearchHandover.php';
require_once __DIR__.'/TermExplainer.php';
require_once __DIR__.'/NasaActions.php';
require_once __DIR__.'/NasaView.php';
require_once __DIR__.'/AdminTestActions.php';

final class Panel
{
    private static function entry(): string
    {
        $entry = $_SERVER['SCRIPT_NAME'] ?? '/loader.php';
        return is_string($entry) && preg_match('~^/[A-Za-z0-9_./-]+\.php$~D', $entry) ? $entry : '/loader.php';
    }
    private static function escape(string|int $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function input(string $name): string
    {
        $value = $_POST[$name] ?? '';
        if (!is_string($value)) {
            throw new RuntimeException('Invalid input.');
        }
        return $value;
    }

    public static function serve(string $directory, string $path, string $method): void
    {
        umask(0077);
        $config = PrivateStorage::config($directory);
        if (getenv('AICONTROL_PIN_VERIFIED') === '1') {
            $config['access_mode'] = 'pin';
        } elseif (getenv('AICONTROL_PUBLIC_TESTING') === '1') {
            $config['access_mode'] = 'testing';
        }
        $passwordMode = $config['access_mode'] === 'password';
        if (!$passwordMode && !AccessPolicy::permits($config['access_mode'], $_SERVER, getenv('AICONTROL_LOCAL_DEV') === '1')) {
            http_response_code(403);
            header('Cache-Control: no-store');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Host-protected access is required. Configure server authentication for this directory.';
            return;
        }
        $store = new PanelStore($directory . '/panel.sqlite');
        $secure = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTPS'] ?? '') === '1';
        $local = getenv('AICONTROL_LOCAL_DEV') === '1'
            && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        if (!$secure && !$local) {
            throw new RuntimeException('HTTPS required.');
        }
        header('Cache-Control: no-store');
        $assetNonce = base64_encode(random_bytes(18));
        header("Content-Security-Policy: default-src 'none'; style-src 'self' 'nonce-{$assetNonce}'; script-src 'self' 'nonce-{$assetNonce}'; connect-src 'self'; img-src data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        if (!in_array($path, ['/', '/loader.php', '/health'], true)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo '{"error":"not_found"}';
            return;
        }
        if (!in_array($method, ['GET', 'HEAD', 'POST'], true) || ($path === '/health' && $method === 'POST')) {
            http_response_code(405);
            header('Allow: GET, HEAD' . ($path === '/health' ? '' : ', POST'));
            return;
        }
        if ($path === '/health') {
            header('Content-Type: application/json');
            if ($method !== 'HEAD') {
                echo '{"status":"ok","scope":"metadata_panel_only","broker_enabled":false,"deployment_enabled":false}';
            }
            return;
        }
        session_name('aicontrol_panel');
        $panelCookie = $_COOKIE['aicontrol_panel'] ?? '';
        session_id(is_string($panelCookie) && preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $panelCookie) ? $panelCookie : '');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_save_path($directory . '/sessions');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
        if (!session_start()) {
            throw new RuntimeException('Session storage unavailable.');
        }
        if (!$passwordMode) {
            $identity = hash('sha256', $config['access_mode'] . ':' . ($_SERVER['REMOTE_USER'] ?? 'loopback'));
            if (($_SESSION['host_identity'] ?? '') !== $identity) {
                session_regenerate_id(true);
                $_SESSION = ['host_identity' => $identity];
            }
        }
        if (($_SESSION['authenticated'] ?? false) && (($_SESSION['expires'] ?? 0) < time())) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        if (!$passwordMode) {
            // Recheck server identity on every request; an old app cookie cannot bypass its removal.
            $_SESSION['authenticated'] = true;
            $_SESSION['expires'] = time() + 1800;
        }
        $error = '';
        // Public demo sessions are not owner authentication, even with a valid CSRF token.
        $postedAction=is_string($_POST['action']??null)?$_POST['action']:'';
        if($config['access_mode']==='testing'&& (in_array($_GET['view']??null,['admin','nasa'],true)
            ||$method==='POST'&&(str_starts_with($postedAction,'admin_')||str_starts_with($postedAction,'vault_')||str_starts_with($postedAction,'nasa_')))){
            http_response_code(403);header('Content-Type: application/json; charset=utf-8');
            echo '{"error":"Admin, credentials and NASA Managed require owner-authenticated access."}';return;
        }
        if (($_GET['research_api'] ?? '') === 'status' && $method === 'GET') {
            if (!($_SESSION['authenticated'] ?? false) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
                Diagnostics::record('research.status.denied',['authenticated'=>(bool)($_SESSION['authenticated']??false),'csrf_header_present'=>isset($_SERVER['HTTP_X_CSRF_TOKEN'])]);
                http_response_code(403); header('Content-Type: application/json'); echo '{"error":"Research session verification failed."}'; return;
            }
            session_write_close();
            ResearchApi::status($directory, (int)($_GET['run_id'] ?? 0));
            return;
        }
        if ($method === 'POST') {
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
                http_response_code(413);
                return;
            }
            if (!hash_equals($_SESSION['csrf'], self::input('csrf'))) {
                Diagnostics::record('panel.csrf.denied',['method'=>'POST','action'=>substr(self::input('action'),0,80)]);
                http_response_code(403);
                echo 'Request verification failed.';
                return;
            }
            $action = self::input('action');
            if ($action === 'login' && $passwordMode) {
                $client = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
                $attempt=$store->reserveLoginAttempt($client);
                if ($attempt===null) {
                    http_response_code(429);
                    header('Retry-After: 600');
                    $error = 'Too many attempts. Try again in ten minutes.';
                } elseif (strlen(self::input('password')) <= 256 && password_verify(self::input('password'), $config['password_hash'])) {
                    $store->releaseSuccessfulLoginAttempt($client,$attempt);
                    session_regenerate_id(true);
                    $_SESSION = ['authenticated' => true, 'expires' => time() + 1800, 'csrf' => bin2hex(random_bytes(32))];
                    header('Location: ' . self::entry(), true, 303);
                    return;
                } else {
                    // The reservation already counts this failure. Unknown or
                    // interrupted verification also retains its allowance.
                    http_response_code(401);
                    $error = 'Sign-in failed.';
                }
            } elseif (!($_SESSION['authenticated'] ?? false)) {
                http_response_code(401);
                $error = 'Sign in to record a proposal.';
            } elseif (str_starts_with($action,'admin_test_')) {
                session_write_close();AdminTestActions::serve($directory,$action,self::entry());return;
            } elseif (str_starts_with($action,'nasa_')) {
                session_write_close();NasaActions::serve($directory,$action,self::entry());return;
            } elseif (str_starts_with($action,'system_')) {
                $json=($_GET['systems_api']??'')==='1';
                try {
                    if($config['access_mode']==='testing')throw new RuntimeException('Systems control requires PIN, password or host-authenticated access.');
                    $systems=new Systems($directory);$id=(int)self::input('system_id');$runId=(int)self::input('run_id');$systemOwner=hash('sha256',session_id());$enrollmentId=self::input('enrollment_id');$terminalId=self::input('terminal_id');$terminalResult=null;
                    $guard=new SystemGuard($systems);
                    if($action==='system_guard_setup')$guard->setup(self::input('systems_password'),self::input('systems_password_confirm'));
                    elseif($action==='system_guard_unlock')$guard->unlock(self::input('systems_password'));
                    elseif($action==='system_guard_lock')$guard->lock();
                    elseif($action==='system_guard_change')$guard->change(self::input('systems_current_password'),self::input('systems_new_password'),self::input('systems_new_confirm'));
                    else $guard->requireUnlocked();
                    session_write_close();
                    if(in_array($action,['system_guard_setup','system_guard_unlock','system_guard_lock','system_guard_change'],true)){}
                    elseif($action==='system_notes_save')(new SystemNotebook($systems))->edit($id,self::input('notes'),self::input('secret_label'),self::input('secret_value'));
                    elseif($action==='system_notes_reveal') {header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['protected_record'=>(new SystemNotebook($systems))->view($id,true)['revealed']],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);return;}
                    elseif(str_starts_with($action,'system_playbook_')){
                        $playbooks=new SystemPlaybooks($systems);$operationId=self::input('operation_id');
                        if($action==='system_playbook_prepare')$operationId=$playbooks->prepare($id,self::input('recipe'));
                        elseif($action==='system_playbook_apply')$playbooks->start($operationId,$id,self::input('confirmation'),$_POST);
                        elseif($action==='system_playbook_status'||$action==='system_playbook_stop')$playbooks->reconcile($operationId,$id,$action==='system_playbook_stop');
                        elseif($action==='system_playbook_release')$playbooks->release($operationId,$id,self::input('confirmation'));
                        else throw new RuntimeException('Unknown guided server action.');
                        if($json){$op=$playbooks->get($operationId,$id);header('Content-Type: application/json');echo json_encode(['playbook'=>array_intersect_key($op,array_flip(['id','system_id','status','blocking']))],JSON_THROW_ON_ERROR);return;}
                        header('Location: '.self::entry().'?view=systems&system='.$id.'&operation='.$operationId.'#server-operation',true,303);return;
                    }
                    elseif($action==='system_prepare'){$enrollment=(new SystemEnrollment($systems))->prepare(self::input('risk_level'));$enrollmentId=$enrollment['id'];}
                    elseif($action==='system_import_discovery'){$enrollment=(new SystemEnrollment($systems))->importDiscovery($enrollmentId,self::input('discovery_reply'));$id=(int)$enrollment['system_id'];}
                    elseif($action==='system_connect'){
                        $enrollments=new SystemEnrollment($systems);$s=$enrollments->receipt($enrollmentId,self::input('setup_reply'));$id=(int)$s['id'];
                        $host=strtolower(trim(self::input('connection_host')));$port=self::input('connection_port');
                        if((!filter_var($host,FILTER_VALIDATE_IP)&&!preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D',$host))||!ctype_digit($port)||(int)$port<1||(int)$port>65535)throw new RuntimeException('Enter a valid connection address and SSH port.');
                        if($systems->query('SELECT id FROM system_terminals WHERE system_id=? AND enabled=1 AND expires_at>?',[$id,time()])&&($s['host']!==$host||(int)$s['port']!==(int)$port))throw new RuntimeException('Disconnect existing consoles before changing the connection address.');
                        $systems->write('UPDATE systems SET host=?,port=? WHERE id=?',[$host,(int)$port,$id]);
                        $terminal=(new SystemTerminal($systems))->connect($id,$systemOwner);$terminalId=$terminal['id'];$runId=(int)$terminal['run_id'];
                    }
                    elseif($action==='system_console_connect'){$terminal=(new SystemTerminal($systems))->connect($id,$systemOwner);$terminalId=$terminal['id'];$runId=(int)$terminal['run_id'];}
                    elseif(in_array($action,['system_console_command','system_console_disconnect'],true)){
                        $console=new SystemTerminal($systems);$terminal=$console->get($terminalId,$systemOwner);if((int)$terminal['system_id']!==$id)throw new RuntimeException('Console does not belong to this system.');$runId=(int)$terminal['run_id'];
                        if($action==='system_console_command')$terminalResult=$console->command($terminalId,$systemOwner,self::input('command'));
                        else{$console->disconnect($terminalId,$systemOwner);$terminalId='';}
                    }
                    elseif($action==='system_add')$id=$systems->add(self::input('name'),self::input('host'),self::input('port'),self::input('username'),self::input('access'),'');
                    elseif($action==='system_test'){$systems->pin($id,self::input('fingerprint'));$systems->verify($id);}
                    elseif($action==='system_revoke')$systems->revoke($id);
                    elseif($action==='system_delete'){$systems->purge($id,self::input('confirmation'));$id=0;}
                    elseif($action==='system_start')$runId=(new SystemsAgent($systems))->start($id,self::input('task'),self::input('project_id')===''?null:(int)self::input('project_id'));
                    elseif(in_array($action,['system_step','system_stop'],true)){
                        if((int)$systems->run($runId)['system_id']!==$id)throw new RuntimeException('Task does not belong to this system.');
                        if($action==='system_stop')$systems->stop($runId);else (new SystemsAgent($systems))->step($runId);
                    }else throw new RuntimeException('System operation unavailable.');
                    if($json){header('Content-Type: application/json');echo json_encode(['run'=>$runId&&!str_starts_with($action,'system_guard_')?$systems->run($runId):null,'terminal_result'=>$terminalResult],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);return;}
                    header('Location: '.self::entry().'?view=systems&system='.$id.($runId?'&system_run='.$runId:'').($enrollmentId?'&enrollment='.$enrollmentId:'').($terminalId?'&terminal='.$terminalId.'#ssh-console':''),true,303);return;
                }catch(Throwable $failure){
                    Diagnostics::record('systems.action.failed',['action'=>$action],$failure);http_response_code(422);$error=Diagnostics::clean($failure->getMessage());
                    if($json){header('Content-Type: application/json');echo json_encode(['error'=>$error],JSON_INVALID_UTF8_SUBSTITUTE);return;}
                }
            } elseif ($action === 'admin_project_json') {
                try {
                    $project=ProjectInspector::id($_POST['project_id']??null);
                    $run=ProjectInspector::id($_POST['run_id']??'0',true);
                    $kind=self::input('kind');
                    session_write_close();
                    ProjectInspector::download($directory,$project,$run,$kind);return;
                } catch(Throwable $failure) {
                    http_response_code(422);header('Content-Type: application/json; charset=utf-8');echo '{"error":"Choose a valid project and investigation JSON export."}';return;
                }
            } elseif ($action === 'admin_data') {
                try {
                    $raw=self::input('record_id');
                    if(!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$raw))throw new RuntimeException('Choose a valid record.');
                    (new AdminData($directory))->change(self::input('operation'),(int)$raw,self::input('confirmation'));
                    $_SESSION['csrf']=bin2hex(random_bytes(32));
                    $_SESSION['flash']='Saved data updated. Refresh any other open workspace pages. Provider charges are unchanged.';
                    header('Location: '.self::entry().'?view=admin',true,303);return;
                } catch(Throwable $failure) {
                    Diagnostics::record('admin.data.failed',['operation'=>is_string($_POST['operation']??null)?substr($_POST['operation'],0,80):'invalid'],$failure);
                    http_response_code(422);
                    $safe=['Confirmation did not match. No data was changed.','Choose a valid record.','Unknown data operation.','The selected record no longer exists. Refresh Admin.','Database relationships require repair; no data was changed.','Another AI request is finishing. Wait before asking for suggestions or starting research.'];
                    $error=in_array($failure->getMessage(),$safe,true)?$failure->getMessage():'Data management did not complete. Review the saved records and Admin diagnostic log.';
                    if(str_starts_with($failure->getMessage(),'Another AI request is finishing.'))$error='An AI request is still running. Wait for it to finish before changing saved data.';
                }
            } elseif ($action === 'diagnostic_client') {
                $last=(int)($_SESSION['diagnostic_client_at']??0);
                if (time()-$last<5) { http_response_code(429); return; }
                $_SESSION['diagnostic_client_at']=time();
                $responseDetails=[];
                foreach(['http_status','content_type','redirected','response_path','error_kind'] as $field)$responseDetails[$field]=substr(self::input($field),0,160);
                Diagnostics::record('browser.research.failed',array_merge($responseDetails,['stage'=>substr(self::input('stage'),0,80),'message'=>substr(self::input('message'),0,1000),'run_id'=>(int)self::input('run_id')]));
                http_response_code(204);return;
            } elseif ($action === 'research_define_term') {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                if($config['access_mode']==='testing'){http_response_code(403);echo '{"error":"Owner access is required for quick explanations."}';return;}
                session_write_close();ignore_user_abort(true);
                try {
                    $project=filter_var(self::input('project_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                    $node=filter_var(self::input('node_id')?:'0',FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
                    if($project===false||$node===false)throw new TermHelpError('Choose saved research text.');
                    echo json_encode((new TermExplainer($directory))->explain($project,self::input('source_kind'),$node,self::input('term'),self::input('retry')==='1'),JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
                } catch(Throwable $failure) {
                    Diagnostics::record('term_help.endpoint',[],$failure);
                    http_response_code($failure instanceof TermHelpError?$failure->status:409);
                    echo json_encode(['error'=>$failure instanceof TermHelpError?$failure->getMessage():'Another AI request may be finishing. Click again when it is idle; no automatic retry was made.','allow_retry'=>$failure instanceof TermHelpError&&$failure->retry],JSON_THROW_ON_ERROR);
                }
                return;
            } elseif ($action === 'research_brief_usage') {
                if($config['access_mode']==='testing'){http_response_code(403);header('Content-Type: application/json');echo '{"error":"Owner access is required for writing-help usage."}';return;}
                session_write_close();require_once __DIR__.'/BriefSuggestions.php';header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
                try{echo json_encode(['allowance'=>(new BriefSuggestions($directory))->usage()],JSON_THROW_ON_ERROR);}catch(Throwable){http_response_code(503);echo '{"error":"Writing-help usage could not be read. No allowance was reset."}';}return;
            } elseif ($action === 'research_brief_suggest') {
                session_write_close();
                require_once __DIR__.'/BriefSuggestions.php';
                BriefSuggestions::serve($directory,$_POST['draft']??'',$_POST['name']??'',$_POST['count']??'3',$_POST['avoid']??[]);
                return;
            } elseif ($action === 'research_status') {
                session_write_close();
                ResearchApi::status($directory,(int)self::input('run_id'));
                return;
            } elseif ($action === 'logout' && $passwordMode) {
                $_SESSION = [];
                session_destroy();
                setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
                header('Location: ' . self::entry(), true, 303);
                return;
            } elseif ($action==='research_handover') {
                if($config['access_mode']==='testing'){http_response_code(403);header('Content-Type: application/json');echo '{"error":"Owner access is required for a complete project export."}';return;}
                session_write_close();
                try{ResearchHandover::serve($directory,(int)self::input('project_id'),self::input('format')?:'view','<input type="hidden" name="csrf" value="'.self::escape($_SESSION['csrf']).'">',self::entry());}
                catch(Throwable $failure){http_response_code(422);header('Content-Type: application/json');echo '{"error":"Research handover could not be created. Choose a saved project and try again."}';Diagnostics::record('research.handover.failed',['project_id'=>(int)self::input('project_id')],$failure);}
                return;
            } elseif (in_array($action, ['research_concept_chat','research_focus_node','research_explore','research_promote_layer','research_demote_layer','research_park','research_lock_evidence','research_settings','research_rank','research_start','research_continue','research_finish','research_repair','research_step','research_stop','research_learn','research_export'], true)) {
                // Release this session before provider I/O so polling and Stop can proceed.
                session_write_close();
                ResearchApi::action($directory, $action, self::entry(), ($_GET['research_api'] ?? '') === '1');
                return;
            } else {
                try {
                    if ($action === 'vault_save' || $action === 'vault_delete') {
                        (new Vault($directory))->change(self::input('name'), self::input('type'), self::input('value'), self::input('share') === '1', $action === 'vault_delete');
                        $_SESSION['flash'] = 'Private vault updated.';
                    } elseif ($action === 'research_project') {
                        $projectId=(new Research($directory))->create(self::input('name'), self::input('brief'));
                        $next=self::entry().'?view=research&project='.$projectId;
                        if(self::input('start_research')==='1'){
                            try{$newRun=(new Investigation($directory))->createRun($projectId);$next.='&run='.$newRun.'&map=1';}
                            catch(Throwable $failure){Diagnostics::record('research.create_start.blocked',['project_id'=>$projectId],$failure);$_SESSION['flash']='Project saved. Research did not start: '.Diagnostics::clean($failure->getMessage()).' Open this project to start when ready.';}
                        }
                        header('Location: '.$next,true,303);return;
                    } elseif ($action === 'research_chat') {
                        (new Research($directory))->chat((int)self::input('project_id'), self::input('message'), self::input('web') === '1');
                    } elseif ($action === 'research_resolve') {
                        (new Research($directory))->resolve((int)self::input('message_id'));
                    } elseif ($action === 'project') {
                        $store->createProject(self::input('name'), self::input('brief'));
                        $_SESSION['flash'] = 'Project created. Add its first task when you are ready.';
                    } elseif ($action === 'task') {
                        $project = filter_var(self::input('project_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if ($project === false) {
                            throw new RuntimeException('Invalid project.');
                        }
                        $store->createTask($project, self::input('title'), self::input('criteria'));
                        $_SESSION['flash'] = 'Task proposal saved.';
                    } elseif ($action === 'run_agent') {
                        $task = filter_var(self::input('task_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if ($task === false) {
                            throw new RuntimeException('Invalid task.');
                        }
                        AgentRunner::run($store, $task);
                    } else {
                        throw new RuntimeException('Operation unavailable.');
                    }
                    header('Location: ' . self::entry() . '?view=' . (str_starts_with($action, 'vault_') ? 'admin' : (str_starts_with($action, 'research_') ? 'research' : 'projects')), true, 303);
                    return;
                } catch (Throwable $failure) {
                    Diagnostics::record('panel.action.failed',['action'=>$action],$failure);
                    http_response_code(422);
                    $error = 'Operation could not be completed. Check the selected project and field lengths.';
                    if (str_starts_with($action, 'vault_')) { $error = 'Vault could not be updated. Use a lowercase name with underscores, and a nonempty value up to 8000 bytes. Existing private data was retained.'; }
                    if(str_starts_with($action,'vault_')&&$failure->getMessage()==='SSH credentials are managed in Systems.')$error='SSH credentials are managed in Systems. Use Disconnect there to revoke a key.';
                    if (str_starts_with($action, 'research_')) { $error = 'Research request could not be started. Check the provider connection, field lengths, pending requests and ten-request hourly limit.'; }
                    if ($action === 'research_project') { $error='Enter a working title (up to 160 bytes) and a research brief (up to 4,000 bytes). Your draft is retained.'; }
                    if ($action === 'run_agent') {
                        $safeMessages = ['An agent run is already active.', 'Testing limit reached: three runs per hour.', 'Task not found.', 'Invalid server model configuration.', 'OpenAI is not connected. Configure AICONTROL_OPENAI_API_KEY directly on the server.'];
                        $error = in_array($failure->getMessage(), $safeMessages, true)
                            ? $failure->getMessage() : 'The task could not be run. Review its current status and the server connection before trying again.';
                    }
                }
            }
        }
        header('Content-Type: text/html; charset=utf-8');
        if ($method === 'HEAD') {
            return;
        }
        $entry = self::entry();
        ob_start(static fn(string $html): string => str_replace(
            ['href="/loader.php', 'action="/loader.php', 'href="/assets/panel.css"', 'src="/assets/panel.js"'],
            ['href="' . $entry, 'action="' . $entry, 'href="' . $entry . '?asset=panel.css"', 'src="' . $entry . '?asset=panel.js"'],
            $html
        ));
        $csrf = '<input type="hidden" name="csrf" value="' . self::escape($_SESSION['csrf']) . '">';
        $views = ['overview', 'projects', 'runs', 'systems', 'history', 'admin', 'research','nasa'];
        $view = is_string($_GET['view'] ?? null) && in_array($_GET['view'], $views, true) ? $_GET['view'] : 'overview';
        $authenticated = (bool) ($_SESSION['authenticated'] ?? false);
        $data = $authenticated ? $store->snapshot() : ['projects' => [], 'tasks' => [], 'events' => [], 'runs' => []];
        if ($authenticated && $view === 'admin') { $data['vault'] = (new Vault($directory))->entries(); }
        if($authenticated&&$view==='nasa'){
            $nasa=new NasaStore($directory);$data['nasa']=['sources'=>$nasa->sources(),'workspace'=>null,'source'=>null];
            try{
                if(isset($_GET['nasa_project']))$data['nasa']['workspace']=$nasa->workspace(NasaStore::id($_GET['nasa_project']));
                else{$choice=is_string($_GET['source']??null)?$_GET['source']:'';$sources=$data['nasa']['sources'];$source=$choice!==''?array_values(array_filter($sources,static fn($s)=>$s['kind'].':'.$s['id']===$choice))[0]??null:($sources[0]??null);$data['nasa']['source']=$source;if($source&&$source['managed_id'])$data['nasa']['workspace']=$nasa->workspace((int)$source['managed_id']);}
            }catch(Throwable $failure){$error='The selected managed project is unavailable. Choose a saved project.';}
            if($data['nasa']['workspace']&&is_string($_GET['engineering_cycle']??null)){
                $workspace=&$data['nasa']['workspace'];
                foreach($workspace['everyday']['groups']['cycle']??[] as $cycle){$cycleKey=$cycle['object_key']??$cycle['key']??'';if($cycleKey===$_GET['engineering_cycle']){$workspace['everyday']['cycle_obligations_by_cycle'][$cycleKey]=$nasa->engineeringCycleObligations((int)$workspace['project']['id'],$cycleKey);break;}}
                unset($workspace);
            }
            if($data['nasa']['workspace']&&isset($_GET['promote_run'],$_GET['promote_node'])){
                try{$selected=$nasa->researchSource((int)$data['nasa']['workspace']['project']['id'],'research_node',NasaStore::id($_GET['promote_node']),NasaStore::id($_GET['promote_run']));$data['nasa']['workspace']['research_choices'][]=['source_kind'=>'research_node','source_id'=>$selected['source_id'],'run_id'=>$selected['run_id'],'title'=>$selected['title'],'content'=>$selected['content'],'eligible_evidence'=>false];}catch(Throwable){$error='The selected research idea is unavailable in this planning project.';}
            }
            if($data['nasa']['workspace']&&is_string($_GET['phase_preview']??null)&&isset(NasaKnowledge::phases()[$_GET['phase_preview']]))$data['nasa']['workspace']['preview']=$nasa->readiness((int)$data['nasa']['workspace']['project']['id'],$_GET['phase_preview']);
            if($data['nasa']['workspace'])foreach(array_keys(NasaKnowledge::phases()) as $phase)$data['nasa']['workspace']['phase_coverage'][$phase]=$nasa->readiness((int)$data['nasa']['workspace']['project']['id'],$phase);
        }
        if($authenticated&&$view==='systems'){
            try{$data['systems']=$config['access_mode']==='testing'?['allowed'=>false]:(new Systems($directory))->snapshot((int)($_GET['system']??($id??0)),(int)($_GET['system_run']??($runId??0)));}
            catch(Throwable $failure){$error='The selected system or task is unavailable.';$data['systems']=(new Systems($directory))->snapshot();}
            if(($data['systems']['allowed']??true)!==false){
                $systemStore=new Systems($directory);
                $data['systems']['guard']=(new SystemGuard($systemStore))->state();
                $selectedSystem=(int)($data['systems']['selected']['id']??0);
                if($selectedSystem){$data['systems']['notebook']=(new SystemNotebook($systemStore))->view($selectedSystem);if($data['systems']['guard']['unlocked']){$books=new SystemPlaybooks($systemStore);$data['systems']['playbooks']=$books->history($selectedSystem);$requestedOperation=(string)($_GET['operation']??($operationId??''));if($requestedOperation)try{$data['systems']['playbook']=$books->get($requestedOperation,$selectedSystem);}catch(Throwable $failure){$error='The selected server action is unavailable.';}}}
                try{if($data['systems']['guard']['unlocked'])$data['systems']['setup']=(new SystemEnrollment($systemStore))->view((string)($_GET['enrollment']??($enrollmentId??'')));}catch(Throwable $failure){$error=Diagnostics::clean($failure->getMessage());}
                try{
                    $requestedTerminal=(string)($_GET['terminal']??($terminalId??''));
                    if($requestedTerminal&&$data['systems']['guard']['unlocked']){$data['systems']['terminal']=(new SystemTerminal($systemStore))->view($requestedTerminal,hash('sha256',session_id()));if((int)$data['systems']['terminal']['system_id']!==(int)($data['systems']['selected']['id']??0))throw new RuntimeException('Console does not belong to the selected system.');}
                }catch(Throwable $failure){unset($data['systems']['terminal']);$error=Diagnostics::clean($failure->getMessage());}
                if($method==='POST')foreach(['discovery_reply','setup_reply','connection_host','connection_port'] as $field)$data['systems']['setup_values'][$field]=self::input($field);
            }
        }
        if ($authenticated && $view === 'research') { $data['research'] = (new Research($directory))->snapshot(); $data['research']['owner_controls']=$config['access_mode']!=='testing'; $data['research']['workspace'] = (new Investigation($directory))->workspace((int)($_GET['project'] ?? 0), (int)($_GET['run'] ?? 0),($_GET['new']??'')==='1'||(($action??'')==='research_project'&&$error!=='')); }
        if(($action??'')==='research_project'&&$error!==''&&isset($data['research']))$data['research']['create_values']=['name'=>self::input('name'),'brief'=>self::input('brief')];
        $data['asset_nonce'] = $assetNonce;
        PanelView::render($data, $view, $csrf, $error, $passwordMode, $config, $authenticated);
    }
}
