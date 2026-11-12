<?php
declare(strict_types=1);
namespace AIcontrol;
use AIcontrolWorkspaceUi as Ui;
require_once __DIR__.'/AdminDataView.php';
final class WorkspaceView
{
    public static function admin(array $entries,string $csrf):void {
        $updateUrl=getenv('AICONTROL_UPDATE_STATUS_URL')?:'';
        echo '<section class="card mb-3"'.($updateUrl!==''?' data-update-status-url="'.Ui::e($updateUrl).'"':'').'><div class="card-body"><h2>Application updates</h2>';
        if($updateUrl!=='')echo '<div class="update-notice" data-update-notice hidden aria-live="polite"><div class="update-notice-copy"><strong data-update-label>Checking for updates…</strong><p data-update-detail></p></div><button class="btn btn-outline-secondary" type="button" data-check-updates>Check again</button><a class="btn btn-primary" data-update-review hidden href="/loader.php?installer=1">Review update</a></div>';
        echo '<a class="btn btn-outline-primary" href="/loader.php?installer=1">Check GitHub and install updates</a></div></section>';
        AdminDataView::render((string)getenv('AICONTROL_PRIVATE_DIR'),$csrf);
        echo '<section class="card mb-3"><div class="card-body"><h2>Detailed error and research log</h2><p>Copy this report and send it back for diagnosis. Includes UTC timestamps, request IDs, phase outcomes, provider errors, timing and exception locations. Credentials, vault values and prompt bodies are excluded. Refresh Admin for the latest events. The private log retains approximately 1 MB.</p><button type="button" class="btn btn-primary mb-3" data-copy-diagnostics>Copy contents</button><span class="ms-2" role="status" data-copy-diagnostics-status></span><label class="form-label" for="diagnostics-report">Diagnostic report</label><textarea id="diagnostics-report" class="form-control" rows="16" readonly spellcheck="false">'.Ui::e(Diagnostics::report()).'</textarea></div></section>';
        echo '<div class="alert alert-info">Keys stay masked and are used only by server integrations. Notes marked “Share with AI” are sent to the provider with agent and research requests. Everyone with your workspace PIN can manage this vault.</div><section class="card mb-3"><div class="card-body"><h2>Add or replace an entry</h2><p>Use <code>openai_api_key</code> for the OpenAI connection. Saving an existing name replaces its value. Server environment keys take precedence.</p><form method="post" action="/loader.php?view=admin">'.$csrf.'<input type="hidden" name="action" value="vault_save"><label class="form-label" for="vault-name">Entry name</label><input id="vault-name" class="form-control mb-3" name="name" required pattern="[a-z][a-z0-9_]{0,63}" maxlength="64" autocomplete="off"><label class="form-label" for="vault-type">Entry type</label><select id="vault-type" name="type" class="form-select mb-3"><option value="key">API key or secret</option><option value="note">Private text</option></select><label class="form-label" for="vault-value">New value</label><textarea id="vault-value" name="value" class="form-control mb-3" rows="4" maxlength="8000" required autocomplete="off" spellcheck="false"></textarea><label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="share" value="1"><span class="form-check-label">Share this note with AI (keys are never included in prompts)</span></label><button class="btn btn-primary">Save private entry</button></form></div></section><section class="card"><div class="card-body"><h2>Saved entries</h2>';
        if (!$entries) echo '<p>No private entries yet.</p>';
        foreach($entries as $name=>$entry){
            echo '<article class="mb-3"><h3>'.Ui::e($name).'</h3><p>'.($entry['type']==='key'?'Secret key · value hidden':($entry['share']?'Private text · shared with AI':'Private text · excluded from AI')).' · Updated '.Ui::e(Ui::time($entry['updated_at'])).'</p><form method="post" action="/loader.php?view=admin">'.$csrf.'<input type="hidden" name="action" value="vault_delete"><input type="hidden" name="name" value="'.Ui::e($name).'"><input type="hidden" name="type" value="'.Ui::e($entry['type']).'"><button class="btn btn-outline-danger">Delete '.Ui::e($name).'</button></form></article>';
        }
        echo '</div></section>';
    }
    public static function research(array $data,string $csrf):void { ResearchView::render($data,$csrf); }
}
