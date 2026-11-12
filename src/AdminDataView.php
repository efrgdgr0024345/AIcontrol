<?php
declare(strict_types=1);
namespace AIcontrol;
use AIcontrolWorkspaceUi as Ui;
require_once __DIR__.'/AdminData.php';
final class AdminDataView
{
    private static function control(string $csrf,string $operation,int $id,string $label,string $description): void
    {
        $key='manage-'.$operation.'-'.$id;$token=AdminData::token($operation,$id);
        echo '<details class="admin-data-action"><summary>'.Ui::e($label).'</summary><p>'.Ui::e($description).'</p><form method="post" action="/loader.php?view=admin" data-admin-operation="'.Ui::e($operation).'" data-record-id="'.$id.'">'.$csrf.'<input type="hidden" name="action" value="admin_data"><input type="hidden" name="operation" value="'.Ui::e($operation).'"><input type="hidden" name="record_id" value="'.$id.'"><label class="form-label" for="'.$key.'">Type <strong>'.Ui::e($token).'</strong> to confirm</label><input id="'.$key.'" class="form-control mb-2" name="confirmation" autocomplete="off" required><button class="btn btn-outline-danger" aria-label="'.Ui::e($label.' · '.$token).'">'.Ui::e($label).'</button></form></details>';
    }
    public static function render(string $directory,string $csrf): void
    {
        $admin=new AdminData($directory);$data=$admin->projects();$report=$admin->report();
        echo '<section class="card mb-3"><div class="card-body"><h2>Saved workspace data</h2><p>Review projects and remove the records you no longer want. Deletion cannot be undone here. Clearing history also removes its saved usage and local request counters; provider charges are unchanged. Open pages may need refreshing after a change.</p><p>Currently executing AI requests block these operations until they finish. PIN, credentials, signing identity, installation settings and security throttling are retained.</p><div class="admin-data-groups"><section><h3>Research projects · '.count($data['research']).'</h3>';
        if(!$data['research'])echo '<p>No saved research projects.</p>';
        foreach($data['research'] as $project){$id=(int)$project['id'];
            echo '<details class="admin-project"><summary>#'.$id.' · '.Ui::e($project['name']).' · '.(int)$project['runs'].' investigations · '.(int)$project['messages'].' chat messages</summary><p class="text-break">'.Ui::e($project['brief']).'</p><a href="/loader.php?view=research&amp;project='.$id.'">Open research project</a>';
            self::control($csrf,'reset_research_project',$id,'Clear research history','Remove every investigation, source, passage, evidence relation, ranking, usage record and chat message for this project. Keep its name, brief and settings.');
            self::control($csrf,'clear_research_chat',$id,'Clear conversation','Remove this project’s legacy chat messages and their recorded usage. Investigation evidence stays.');
            foreach($data['runs'] as $run)if((int)$run['project_id']===$id){echo '<div class="admin-record"><p>Investigation #'.(int)$run['id'].' · '.Ui::e($run['status'].' · '.$run['phase']).'</p>';self::control($csrf,'delete_research_run',(int)$run['id'],'Delete investigation','Remove this investigation and all of its saved evidence, sources, nodes, rankings and usage. Keep the project and other investigations.');echo '</div>';}
            self::control($csrf,'delete_research_project',$id,'Delete research project','Remove this project, its settings, conversations and every investigation with all associated evidence and usage.');echo '</details>';
        }
        echo '</section><section><h3>Task projects · '.count($data['projects']).'</h3>';
        if(!$data['projects'])echo '<p>No saved task projects.</p>';
        foreach($data['projects'] as $project){$id=(int)$project['id'];
            echo '<details class="admin-project"><summary>#'.$id.' · '.Ui::e($project['name']).' · '.(int)$project['tasks'].' tasks · '.(int)$project['runs'].' AI runs</summary><p class="text-break">'.Ui::e($project['brief']).'</p>';
            self::control($csrf,'reset_project',$id,'Clear tasks and runs','Remove this project’s tasks, agent outputs, activity and usage. Keep its name and brief.');
            foreach($data['tasks'] as $task)if((int)$task['project_id']===$id){$taskId=(int)$task['id'];echo '<details class="admin-record"><summary>Task #'.$taskId.' · '.Ui::e($task['title'].' · '.$task['status']).'</summary>';self::control($csrf,'reset_task',$taskId,'Clear task history','Remove this task’s AI outputs, usage and activity; reset it to draft and clear evidence. Keep title and acceptance criteria.');self::control($csrf,'delete_task',$taskId,'Delete task','Remove this task, its AI run outputs, usage and related activity.');echo '</details>';}
            self::control($csrf,'delete_project',$id,'Delete task project','Remove this project and all its tasks, agent outputs, usage and activity.');echo '</details>';
        }
        echo '</section></div><details class="admin-bulk"><summary>Workspace cleanup and reset</summary>';
        self::control($csrf,'clear_activity',0,'Clear task activity log','Remove saved task activity events. Projects, tasks and AI outputs remain.');
        self::control($csrf,'clear_diagnostics',0,'Clear diagnostic log','Clear diagnostic events. A new event records this cleanup. Projects, vault and usage remain.');
        self::control($csrf,'clear_suggestions',0,'Clear writing-help history','Remove the private suggestion usage journal and reset its local hourly allowance. Already incurred provider charges are unchanged.');
        self::control($csrf,'reset_workspace',0,'Reset all project data','Delete every task project and research project, all tasks, conversations, investigations, evidence, activity and related usage. Keep PIN, encrypted vault, signing identity, security throttling, configuration, installed code, diagnostics and the separate writing-help journal.');
        echo '</details></div></section>';
        self::technical($report);
    }
    private static function technical(array $report): void
    {
        echo '<details class="card mb-3 admin-technical"><summary class="card-body">Database setup, tables and PHP runtime</summary><div class="card-body"><p>'.Ui::e($report['database_setup']).'</p><p>These values are queried from this server. The report includes server paths and schema, but excludes credentials, environment values and project contents.</p><dl><dt>PHP</dt><dd>'.Ui::e($report['php']['version'].' · '.$report['php']['sapi'].' · '.$report['php']['os_family']).'</dd><dt>Loaded php.ini</dt><dd class="text-break">'.Ui::e($report['php']['ini_file']??'No php.ini path reported').'</dd><dt>Installed release</dt><dd class="text-break">'.Ui::e($report['installed_release']??'Not reported by loader').'</dd></dl>';
        foreach($report['databases'] as $database){echo '<section class="admin-database"><h3>'.Ui::e($database['file']).'</h3><p>'.Ui::e($database['purpose']).'</p><dl><dt>Confirmed file path</dt><dd class="text-break">'.Ui::e($database['path']).'</dd><dt>Engine and health</dt><dd>PDO '.Ui::e($database['driver']).' · SQLite '.Ui::e($database['sqlite_version']).' · '.(int)$database['size_bytes'].' bytes · journal '.Ui::e($database['journal_mode']).' · quick check: '.Ui::e($database['quick_check']).' · foreign-key issues: '.count($database['foreign_key_issues']).'</dd></dl>';
            foreach($database['tables'] as $table){echo '<details class="admin-table"><summary>'.Ui::e($table['name']).' · '.(int)$table['rows'].' rows</summary><div class="table-responsive" tabindex="0" role="region" aria-label="Columns in '.Ui::e($table['name']).'"><table class="table"><thead><tr><th scope="col">Column</th><th scope="col">Type</th><th scope="col">NOT NULL flag</th><th scope="col">Primary key</th></tr></thead><tbody>';foreach($table['columns'] as $column)echo '<tr><td>'.Ui::e($column['name']).'</td><td>'.Ui::e($column['type']).'</td><td>'.($column['notnull']?'Yes':'No').'</td><td>'.($column['pk']?'Yes':'No').'</td></tr>';echo '</tbody></table></div><pre class="admin-schema">'.Ui::e($table['schema']).'</pre></details>';}
            echo '</section>';
        }
        echo '<details><summary>Loaded PHP extensions and PDO drivers</summary><p>PDO drivers: '.Ui::e(implode(', ',$report['php']['pdo_drivers'])).'</p><pre class="admin-schema">'.Ui::e(json_encode($report['php']['extensions'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre></details>';
        echo '<h3>PHP limits and error output configuration</h3><p>PHP warnings and fatal errors are available in the diagnostic log below. This table shows the effective runtime configuration.</p><div class="table-responsive" tabindex="0" role="region" aria-label="PHP runtime settings"><table class="table"><thead><tr><th scope="col">Setting</th><th scope="col">Observed value</th></tr></thead><tbody>';foreach($report['php']['settings'] as $key=>$value)echo '<tr><td>'.Ui::e($key).'</td><td class="text-break">'.Ui::e($value===''?'Not set':(string)$value).'</td></tr>';echo '</tbody></table></div><h3>Other private runtime storage</h3><div class="table-responsive" tabindex="0" role="region" aria-label="Private runtime files"><table class="table"><thead><tr><th scope="col">File or directory</th><th scope="col">Present</th><th scope="col">Bytes</th><th scope="col">Purpose</th></tr></thead><tbody>';
        foreach($report['private_files'] as $file)echo '<tr><td>'.Ui::e($file['file']).'</td><td>'.($file['present']?'Yes':'No').'</td><td>'.($file['bytes']===null?'—':(int)$file['bytes']).'</td><td>'.Ui::e($file['purpose']).'</td></tr>';
        echo '</tbody></table></div><button type="button" class="btn btn-outline-primary mb-2" data-copy-report="admin-technical-report">Copy technical report</button><span class="ms-2" role="status" data-copy-report-status></span><label class="form-label" for="admin-technical-report">Technical report including database schema, extensions and PHP configuration</label><textarea id="admin-technical-report" class="form-control" rows="12" readonly spellcheck="false">'.Ui::e(json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)).'</textarea></div></details>';
    }
}
