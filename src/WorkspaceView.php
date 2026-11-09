<?php
declare(strict_types=1);
namespace AIcontrol;
use AIcontrolWorkspaceUi as Ui;
final class WorkspaceView
{
    public static function admin(array $entries,string $csrf):void {
        echo '<div class="alert alert-info">Keys stay masked and are used only by server integrations. Notes marked “Share with AI” are sent to the provider with agent and research requests. Everyone with your workspace PIN can manage this vault.</div><section class="card mb-3"><div class="card-body"><h2>Add or replace an entry</h2><p>Use <code>openai_api_key</code> for the OpenAI connection. Saving an existing name replaces its value. Server environment keys take precedence.</p><form method="post" action="/loader.php?view=admin">'.$csrf.'<input type="hidden" name="action" value="vault_save"><label class="form-label" for="vault-name">Entry name</label><input id="vault-name" class="form-control mb-3" name="name" required pattern="[a-z][a-z0-9_]{0,63}" maxlength="64" autocomplete="off"><label class="form-label" for="vault-type">Entry type</label><select id="vault-type" name="type" class="form-select mb-3"><option value="key">API key or secret</option><option value="note">Private text</option></select><label class="form-label" for="vault-value">New value</label><textarea id="vault-value" name="value" class="form-control mb-3" rows="4" maxlength="8000" required autocomplete="off" spellcheck="false"></textarea><label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="share" value="1"><span class="form-check-label">Share this note with AI (keys are never included in prompts)</span></label><button class="btn btn-primary">Save private entry</button></form></div></section><section class="card"><div class="card-body"><h2>Saved entries</h2>';
        if (!$entries) echo '<p>No private entries yet.</p>';
        foreach($entries as $name=>$entry){
            echo '<article class="mb-3"><h3>'.Ui::e($name).'</h3><p>'.($entry['type']==='key'?'Secret key · value hidden':($entry['share']?'Private text · shared with AI':'Private text · excluded from AI')).' · Updated '.Ui::e(Ui::time($entry['updated_at'])).'</p><form method="post" action="/loader.php?view=admin">'.$csrf.'<input type="hidden" name="action" value="vault_delete"><input type="hidden" name="name" value="'.Ui::e($name).'"><input type="hidden" name="type" value="'.Ui::e($entry['type']).'"><button class="btn btn-outline-danger">Delete '.Ui::e($name).'</button></form></article>';
        }
        echo '</div></section>';
    }
    public static function research(array $data,string $csrf):void {
        echo '<section class="card mb-3"><div class="card-body"><h2>New research project</h2><form method="post" action="/loader.php?view=research">'.$csrf.'<input type="hidden" name="action" value="research_project"><label class="form-label" for="research-name">Research name</label><input id="research-name" name="name" class="form-control mb-3" maxlength="160" required><label class="form-label" for="research-brief">Research brief</label><textarea id="research-brief" name="brief" class="form-control mb-3" rows="3" maxlength="4000"></textarea><button class="btn btn-primary">Create research project</button></form></div></section>';
        if (!AgentRunner::configured()) echo '<div class="alert alert-info">Connect OpenAI in <a href="/loader.php?view=admin">Admin</a> to use research chat. You can create projects now.</div>';
        foreach($data['projects'] as $project){
            echo '<section class="card mb-3"><div class="card-body"><h2>'.Ui::e($project['name']).'</h2><p class="text-break">'.nl2br(Ui::e($project['brief'])).'</p><div class="research-conversation" role="region" aria-label="Conversation for '.Ui::e($project['name']).'">';
            foreach($data['messages'] as $message)if((int)$message['project_id']===(int)$project['id']){
                echo '<article class="research-message"><h3>'.($message['role']==='user'?'You':'Research assistant').' <small>· '.Ui::e($message['status']).'</small></h3><p class="text-break">'.nl2br(Ui::e($message['text'] ?: 'Waiting for the provider outcome.')).'</p>';
                if($message['status']==='pending' && (int)$message['created_at']<time()-120)echo '<form method="post" action="/loader.php?view=research">'.$csrf.'<input type="hidden" name="action" value="research_resolve"><input type="hidden" name="message_id" value="'.(int)$message['id'].'"><button class="btn btn-outline-secondary">Mark interrupted request as uncertain</button></form>';
                echo '</article>';
            }
            $id=(int)$project['id'];
            echo '</div><form method="post" action="/loader.php?view=research" data-research-chat>'.$csrf.'<input type="hidden" name="action" value="research_chat"><input type="hidden" name="project_id" value="'.$id.'"><label class="form-label" for="research-message-'.$id.'">Message</label><textarea id="research-message-'.$id.'" name="message" class="form-control mb-3" rows="3" maxlength="4000" required></textarea><label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="web" value="1" checked><span class="form-check-label">Search the web for current sources (provider charges apply)</span></label><p>Up to ten requests per hour. Shared notes are included. Keep credentials out of messages.</p><button class="btn btn-primary"'.(AgentRunner::configured()?'':' disabled').'>Send research message</button><span class="ms-2" data-research-status role="status"></span></form></div></section>';
        }
    }
}
