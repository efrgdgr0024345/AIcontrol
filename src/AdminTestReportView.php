<?php
declare(strict_types=1);
namespace AIcontrol;
require_once __DIR__.'/AdminTestReports.php';

/** Private owner-facing delivery history, using the existing Admin action boundary. */
final class AdminTestReportView
{
    private static int $field=0;
    private static function e(mixed $value): string {return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function form(string $csrf,string $action,array $fields=[]): void
    {
        echo '<form method="post" enctype="multipart/form-data" action="/loader.php?view=admin" class="mb-3">'.$csrf;
        foreach(['action'=>$action]+$fields as $key=>$value)echo '<input type="hidden" name="'.self::e($key).'" value="'.self::e($value).'">';
    }
    private static function field(string $name,string $label,string $value='',int $max=160,bool $area=false,bool $required=true,int $rows=3): void
    {
        $id='admin-test-field-'.++self::$field;echo '<label class="form-label" for="'.$id.'">'.self::e($label).'</label>';
        if($area)echo '<textarea id="'.$id.'" class="form-control mb-3" name="'.self::e($name).'" rows="'.$rows.'" maxlength="'.$max.'"'.($required?' required':'').' spellcheck="false">'.self::e($value).'</textarea>';
        else echo '<input id="'.$id.'" class="form-control mb-3" name="'.self::e($name).'" value="'.self::e($value).'" maxlength="'.$max.'"'.($required?' required':'').($name==='expected_sha'||$name==='claimed_tested_sha'?' pattern="[a-f0-9]{40}" autocomplete="off"':'').'>';
    }
    private static function choice(string $name,string $label,array $options,string $selected=''): void
    {
        $id='admin-test-field-'.++self::$field;echo '<label class="form-label" for="'.$id.'">'.self::e($label).'</label><select id="'.$id.'" class="form-select mb-3" name="'.self::e($name).'">';
        foreach($options as $value=>$text)echo '<option value="'.self::e($value).'"'.((string)$value===$selected?' selected':'').'>'.self::e($text).'</option>';
        echo '</select>';
    }
    private static function button(string $label,string $class='btn-outline-primary',bool $disabled=false): void
    {
        echo '<button class="btn '.$class.'"'.($disabled?' disabled':'').'>'.self::e($label).'</button></form>';
    }
    private static function scope(string $scope): string
    {
        return ['fixture'=>'Synthetic fixture','local'=>'Local environment / server inspection','live_provider'=>'Live provider request','hosted'=>'Hosted website / serving host','manual'=>'Manual observation'][$scope]??$scope;
    }
    private static function origin(string $origin): string
    {
        return ['server_observed'=>'Observed by a built-in server check','owner_reported'=>'Reported by the owner or an imported submission','browser_reported'=>'Reported browser observation'][$origin]??'Origin: '.str_replace('_',' ',$origin);
    }
    private static function outcome(string $value): string
    {
        return ['not_run'=>'No results yet','passed_as_reported'=>'Planned checks passed as recorded','failed'=>'A failed check is recorded','uncertain'=>'Uncertain result recorded','incomplete'=>'Results are incomplete','passed'=>'Passed','skipped'=>'Skipped','pending'=>'Not run','running'=>'Started; result not recorded'][$value]??ucfirst(str_replace('_',' ',$value));
    }
    private static function lifecycle(string $value): string
    {
        return ['open'=>'Open record','finished'=>'Finished record','abandoned'=>'Abandoned record'][$value]??ucfirst($value);
    }
    private static function releaseState(string $value): string
    {
        return [
            'matches_expected'=>'The recorded server release matches the expected commit.',
            'mismatch'=>'A recorded server release or a reported tested commit differs from the expected commit. Check the source-labelled commits below.',
            'mixed'=>'More than one installed release was recorded during this test.',
            'unknown'=>'The serving release could not be established.',
            'not_specified'=>'No expected commit was supplied.',
        ][$value]??'Release comparison: '.str_replace('_',' ',$value);
    }
    private static function copy(string $id,string $label,string $contents,int $rows=10): void
    {
        echo '<div class="mb-3"><button type="button" class="btn btn-outline-primary mb-2" data-copy-report="'.self::e($id).'">Copy '.self::e($label).'</button><span class="ms-2" role="status" data-copy-report-status></span><label class="form-label" for="'.self::e($id).'">'.self::e(ucfirst($label)).'</label><textarea id="'.self::e($id).'" class="form-control" rows="'.$rows.'" readonly spellcheck="false">'.self::e($contents).'</textarea></div>';
    }
    private static function start(string $csrf): void
    {
        echo '<details class="mb-3"><summary>Start a delivery or test record</summary><p>Start with the built-in serving-host checks and a research-flow observation. Starting a record does not run a test.</p>';
        self::form($csrf,'admin_test_start');self::field('title','Record name, for example: Research recovery after update');self::field('expected_sha','Expected release commit (optional, 40 lowercase hexadecimal characters)','',40,false,false);
        echo '<p class="text-secondary">The expected commit is supplied by you. Each event separately retains the release the server could observe.</p><details class="mb-3"><summary>Advanced: custom check plan</summary><p>Leave this empty to use the normal plan. A custom plan is a JSON list of check IDs, names and scopes; it changes what this record expects, not what the website is allowed to execute.</p>';
        self::field('plan_json','Custom plan JSON (optional)','',12000,true,false,5);echo '</details>';self::button('Start test record','btn-primary');echo '</details>';
    }
    private static function reports(array $reports): void
    {
        echo '<h3>Saved delivery and test records</h3>';
        if(!$reports){echo '<p>No test records have been saved yet.</p>';return;}
        echo '<div class="table-responsive" role="region" aria-label="Saved delivery and test records" tabindex="0"><table class="table"><thead><tr><th scope="col">Record</th><th scope="col">Progress</th><th scope="col">Recorded results</th><th scope="col">Last activity (UTC)</th></tr></thead><tbody>';
        foreach($reports as $r)echo '<tr><th scope="row"><a href="/loader.php?view=admin&amp;test_report='.(int)$r['id'].'#release-test-record">#'.(int)$r['id'].' · '.self::e($r['title']).'</a></th><td>'.self::e(self::lifecycle((string)$r['lifecycle'])).'</td><td>'.self::e(self::outcome((string)$r['outcome'])).'</td><td>'.self::e($r['last_event_utc']??$r['created_utc']??'Not recorded').'</td></tr>';
        echo '</tbody></table></div><p class="text-secondary">A finished record can contain failures, skipped checks or uncertainty. Reported passes retain their source and test scope.</p>';
    }
    private static function checks(array $bundle,string $csrf,bool $open): void
    {
        $report=$bundle['report'];$summary=$bundle['summary'];$id=(int)$report['id'];$sequence=(int)$bundle['sequence'];
        echo '<h4>Planned checks and current results</h4><div class="table-responsive" role="region" aria-label="Planned test checks and result sources" tabindex="0"><table class="table"><thead><tr><th scope="col">Check</th><th scope="col">Scope</th><th scope="col">Result and source</th></tr></thead><tbody>';
        foreach($summary['checks']??[] as $check){$origin=(string)($check['origin']??'');echo '<tr><th class="text-body" scope="row">'.self::e($check['title']).'<br><small class="text-body">'.self::e($check['id']).'</small></th><td>'.self::e(self::scope((string)$check['scope'])).'</td><td>'.self::e(self::outcome((string)$check['state']));if($origin!=='')echo '<br><small>'.self::e(self::origin($origin)).'</small>';if(($check['summary']??'')!=='')echo '<p>'.self::e($check['summary']).'</p>';echo '</td></tr>';}
        echo '</tbody></table></div>';
        if(!$open)return;
        $allowed=['php_runtime'=>'Check PHP runtime and required extensions','sqlite_health'=>'Check application database integrity','release_match'=>'Compare installed release with expected commit'];
        $serverChecks=array_filter($report['plan'],static fn($check)=>isset($allowed[$check['id']]));
        if(!$serverChecks){echo '<p>This plan has no built-in server checks. Record the observed results below.</p>';return;}
        echo '<h4>Run a built-in server check</h4><p>These bounded checks inspect this serving environment. They do not call an AI provider, drive a browser, run an arbitrary shell command or prove the research flow works.</p>';
        foreach($serverChecks as $check){self::form($csrf,'admin_test_check',['report_id'=>$id,'sequence'=>$sequence,'check_id'=>$check['id']]);self::button($allowed[$check['id']]);}
    }
    private static function append(array $bundle,string $csrf): void
    {
        $r=$bundle['report'];$options=[];foreach($r['plan'] as $check)$options[$check['id']]=$check['title'].' · '.self::scope($check['scope']);
        echo '<details class="mb-3"><summary>Record a test result or browser observation</summary><p>This is a reported result. Saving “Passed” does not turn it into an independently observed server check or verify an application requirement.</p>';
        self::form($csrf,'admin_test_append',['report_id'=>$r['id'],'sequence'=>$bundle['sequence']]);
        self::choice('event_kind','Kind of observation',['check.result'=>'Owner-reported test result','browser.observation'=>'Browser observation'],'check.result');
        self::choice('check_id','Check this observation concerns',$options,isset($options['research_flow'])?'research_flow':(string)array_key_first($options));
        self::choice('outcome','Reported outcome',['uncertain'=>'Uncertain / could not establish','passed'=>'Passed as reported','failed'=>'Failed as reported','skipped'=>'Skipped'],'uncertain');
        self::field('summary','What happened, and what does this result establish?','',1600,true,true,3);self::field('output','Observed output, error log or reproduction details (optional; up to 6,000 UTF-8 bytes)','',6000,true,false,8);self::field('claimed_tested_sha','Commit tested according to this observation (optional)','',40,false,false);
        echo '<p class="text-secondary">Choose the check whose saved scope matches the observation. A fixture result does not establish live-provider or hosted behavior. Earlier events remain in the history.</p>';self::button('Save reported observation','btn-primary');echo '</details>';
        echo '<details class="mb-3"><summary>Finish this record</summary><p>Finishing closes the record with your summary. It does not mark unfinished, failed or uncertain checks as passed.</p>';
        self::form($csrf,'admin_test_finish',['report_id'=>$r['id'],'sequence'=>$bundle['sequence']]);self::field('owner_summary','Owner summary: established results, remaining limits and next steps','',2400,true,true,4);self::button('Finish record with current results','btn-outline-secondary');echo '</details>';
    }
    private static function events(array $bundle): void
    {
        echo '<details class="mb-3"><summary>Retained event history · '.count($bundle['events']).' events</summary>';
        foreach($bundle['events'] as $event){$fields=$event['fields'];echo '<article class="mb-3"><h5>Event '.(int)$event['sequence'].' · '.self::e(str_replace('.',' / ',$event['kind'])).'</h5><p>'.self::e($event['created_utc']).' UTC · '.self::e(self::origin($event['origin'])).'</p>';
            foreach(['summary'=>'Observation','owner_summary'=>'Owner summary','check_id'=>'Check','outcome'=>$event['origin']==='server_observed'?'Server check outcome':'Reported outcome','claimed_tested_sha'=>'Reported tested commit','duration_ms'=>'Recorded duration (ms)','client_utc'=>'Reported client time'] as $key=>$label)if(isset($fields[$key])&&is_scalar($fields[$key])&&(string)$fields[$key]!=='')echo '<p><strong>'.self::e($label).':</strong> '.self::e($fields[$key]).'</p>';
            if(!empty($event['server']['installed_release_sha']))echo '<p>Server-observed release: <code class="text-break">'.self::e($event['server']['installed_release_sha']).'</code></p>';
            if(($fields['output_omitted_bytes']??0)>0)echo '<p class="text-secondary">Output retained: '.(int)($fields['output_retained_bytes']??0).' bytes; '.(int)$fields['output_omitted_bytes'].' additional redacted-output bytes were omitted. The full redacted-output hash is retained in the downloadable report.</p>';
            if(isset($fields['output'])&&is_string($fields['output'])&&$fields['output']!=='')self::copy('admin-test-output-'.(int)$bundle['report']['id'].'-'.(int)$event['sequence'],'event output',$fields['output'],6);
            if(isset($fields['details'])){echo '<details><summary>Structured observation details</summary>';self::copy('admin-test-details-'.(int)$bundle['report']['id'].'-'.(int)$event['sequence'],'structured observation details',json_encode($fields['details'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),6);echo '</details>';}
            echo '</article>';
        }echo '</details>';
    }
    private static function selected(AdminTestReports $store,array $bundle,string $csrf): void
    {
        $r=$bundle['report'];$s=$bundle['summary'];$open=$s['lifecycle']==='open'&&($bundle['integrity']['verified']??false)===true;
        echo '<article id="release-test-record" class="card mb-3"><div class="card-body"><h3>Report #'.(int)$r['id'].' · '.self::e($r['title']).'</h3><p><strong>'.self::e(self::lifecycle($s['lifecycle'])).'</strong> · '.self::e(self::outcome($s['outcome'])).'</p><p>Created '.self::e($r['created_utc']).' UTC · '.(int)$bundle['sequence'].' retained events.</p>';
        if(($s['failure_event_count']??0)>0||($s['uncertain_event_count']??0)>0)echo '<p>History retains '.(int)($s['failure_event_count']??0).' failed observations and '.(int)($s['uncertain_event_count']??0).' uncertain observations. Later results do not remove earlier outputs; inspect event history for each source and sequence.</p>';
        echo '<p>Expected commit <strong>(owner supplied):</strong> '.($r['expected_release_sha']?'<code class="text-break">'.self::e($r['expected_release_sha']).'</code>':'Not supplied').'</p><p>'.self::e(self::releaseState($s['release_state'])).'</p>';
        foreach($s['server_observed_release_shas']??[] as $sha)echo '<p>Server-observed release: <code class="text-break">'.self::e($sha).'</code></p>';
        foreach($s['client_claimed_tested_shas']??[] as $sha)echo '<p>Tested commit <strong>(owner/browser reported):</strong> <code class="text-break">'.self::e($sha).'</code></p>';
        echo '<p class="text-secondary">'.(($bundle['integrity']['verified']??false)?'Retained event-chain integrity checked. This confirms the saved record is internally consistent; it does not independently verify owner or browser reports.':'Saved event-chain integrity could not be verified.').'</p>';
        self::checks($bundle,$csrf,$open);if($open)self::append($bundle,$csrf);elseif($s['lifecycle']==='open')echo '<p>This open record is read-only because its event-chain integrity could not be verified. Inspect Admin diagnostics before recording more results.</p>';else echo '<p>This record is closed. Start a new record for further checks; earlier outputs remain available below.</p>';
        echo '<div class="d-flex flex-wrap gap-2 mb-3">';foreach(['json'=>'Download full report JSON','markdown'=>'Download readable report'] as $format=>$label){self::form($csrf,'admin_test_export',['report_id'=>$r['id'],'format'=>$format]);self::button($label,'btn-outline-secondary');}self::form($csrf,'admin_test_read',['report_id'=>$r['id']]);self::button('View report JSON','btn-outline-secondary');echo '</div>';
        self::events($bundle);echo '<details><summary>Full contents to copy for diagnosis</summary>';self::copy('admin-test-report-json-'.(int)$r['id'],'full test report',$store->export((int)$r['id'],'json'),14);echo '</details></div></article>';
    }
    private static function import(string $csrf): void
    {
        echo '<details><summary>Advanced: import a delivery/test packet</summary><p>Paste one formatted packet from the delivery/testing helper. Imported observations retain their reported origin; an import does not become a built-in server observation.</p>';
        self::form($csrf,'admin_test_import');self::field('packet_json','Delivery/test packet JSON (up to 12,000 UTF-8 bytes)','',12000,true,true,8);self::button('Import packet as a retained report','btn-outline-secondary');echo '</details>';
    }
    public static function render(string $directory,string $csrf): void
    {
        self::$field=0;echo '<section class="card mb-3" id="admin-test-records"><div class="card-body"><h2>Delivery and test records</h2><p>Keep an update’s test plan, serving-release observations, browser results and error output together. Open a record, run bounded server checks, then record what the browser or other testing actually showed.</p>';
        try{
            $store=new AdminTestReports($directory);$rawPage=$_GET['report_page']??'0';$page=is_string($rawPage)&&preg_match('/^[0-9]$/D',$rawPage)?(int)$rawPage:0;$reports=$store->list(20,$page*20);self::start($csrf);self::reports($reports);echo '<nav aria-label="Test report history pages" class="mb-3">';if($page>0)echo '<a class="btn btn-outline-secondary" href="/loader.php?view=admin&amp;report_page='.($page-1).'#admin-test-records">Newer test records</a> ';if(count($reports)===20&&$page<9)echo '<a class="btn btn-outline-secondary" href="/loader.php?view=admin&amp;report_page='.($page+1).'#admin-test-records">Older test records</a>';echo '</nav>';
            $selected=$_GET['test_report']??'';
            if($selected!==''){
                if(!is_string($selected)||!preg_match('/^[1-9][0-9]{0,9}$/D',$selected))throw new \RuntimeException('Choose a saved test record from the list.');
                self::selected($store,$store->report((int)$selected),$csrf);
            }elseif($reports)self::selected($store,$store->report((int)$reports[0]['id']),$csrf);
            self::import($csrf);
        }catch(\Throwable $e){echo '<p role="alert">'.self::e($e instanceof \RuntimeException?$e->getMessage():'Delivery and test records could not be read. Inspect the Admin diagnostic log.').'</p>';}
        echo '</div></section>';
    }
}
