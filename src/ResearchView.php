<?php
declare(strict_types=1);
namespace AIcontrol;
use AIcontrolWorkspaceUi as Ui;
final class ResearchView
{
    private static function form(string $csrf,string $action,int $run,string $label,string $class='btn-primary'):string
    {
        return '<form method="post" action="/loader.php?view=research" data-research-action="'.$action.'">'.$csrf.'<input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="run_id" value="'.$run.'"><button class="btn '.$class.'">'.$label.'</button></form>';
    }
    public static function nodes(array $nodes,?int $parent=null):void
    {
        foreach($nodes as $node)if($node['type']!=='learn' && (($node['parent_id']===null?null:(int)$node['parent_id'])===$parent)){
            echo '<details class="investigation-node" data-node-id="'.(int)$node['id'].'" data-status="'.Ui::e($node['status']).'" open><summary><span>'.Ui::e($node['title']).'</span> <small>'.Ui::e($node['type'].' · '.$node['status']).'</small></summary><div class="node-content">'.Ui::e($node['content']).'</div><div class="node-children">';
            self::nodes($nodes,(int)$node['id']);echo '</div></details>';
        }
    }
    public static function sources(array $sources):void
    {
        foreach($sources as $source){
            if(!Investigation::sourceUrl($source['url']))continue;
            echo '<article class="research-source" data-source-id="'.(int)$source['id'].'"><h4>Source #'.(int)$source['id'].' · '.Ui::e($source['title']).'</h4><a href="'.Ui::e($source['url']).'" target="_blank" rel="noopener noreferrer">'.Ui::e($source['url']).'</a><p>'.Ui::e($source['extract']).'</p><small>'.Ui::e($source['domain']).' · Citation captured '.Ui::e(Ui::time((int)$source['retrieved_at'])).'</small></article>';
        }
    }
    public static function render(array $data,string $csrf):void
    {
        $workspace=$data['workspace'];$project=$workspace['project'];$snapshot=$workspace['snapshot'];$run=$snapshot['run']??null;
        $entry=$_SERVER['SCRIPT_NAME']??'/loader.php';
        if(!preg_match('~^/[A-Za-z0-9_./-]+\.php$~D',$entry))$entry='/loader.php';
        echo '<section class="card mb-3"><div class="card-body"><h2>Research projects</h2><div class="research-project-list">';
        foreach($data['projects'] as $p)echo '<a class="btn btn-outline-secondary" href="/loader.php?view=research&amp;project='.(int)$p['id'].'"'.($project && $p['id']===$project['id']?' aria-current="page"':'').'>'.Ui::e($p['name']).'</a>';
        echo '</div><details class="mt-3"'.(!$project?' open':'').'><summary>Create a research project</summary><form method="post" action="/loader.php?view=research" class="mt-3">'.$csrf.'<input type="hidden" name="action" value="research_project"><label class="form-label" for="research-name">Research name</label><input id="research-name" name="name" class="form-control mb-3" maxlength="160" required><label class="form-label" for="research-brief">Research brief</label><textarea id="research-brief" name="brief" class="form-control mb-3" rows="3" maxlength="4000"></textarea><button class="btn btn-primary">Create research project</button></form></details></div></section>';
        if(!AgentRunner::configured())echo '<div class="alert alert-info">Connect OpenAI in <a href="/loader.php?view=admin">Admin</a> to start an investigation or chat. You can organise projects now.</div>';
        if(!$project)return;
        echo '<section class="card mb-3"><div class="card-body"><h2>'.Ui::e($project['name']).'</h2><p class="text-break">'.Ui::e($project['brief']).'</p><p>Dream → Plan → Evidence → Reconciliation. Up to two verification searches, one phase at a time; each provider request counts towards the shared ten-per-hour limit.</p><form method="post" action="/loader.php?view=research" data-research-action="research_start">'.$csrf.'<input type="hidden" name="action" value="research_start"><input type="hidden" name="project_id" value="'.(int)$project['id'].'"><button class="btn btn-primary"'.(!AgentRunner::configured()?' disabled':'').'>Start research</button></form></div></section>';
        if($run){
            $active=in_array($run['status'],['pending','running'],true);$pending=(bool)array_filter($snapshot['requests'],static fn(array $r):bool=>$r['status']==='pending');$busy=$run['busy_since']!==null;
            echo '<section class="card mb-3 investigation" data-investigation data-entry="'.Ui::e($entry).'" data-run-id="'.(int)$run['id'].'" data-status="'.Ui::e($run['status']).'" data-busy="'.($busy?'1':'0').'"><div class="card-body"><div class="investigation-heading"><div><h2>Investigation #'.(int)$run['id'].'</h2><p data-research-summary role="status" aria-live="polite">'.Ui::e($run['status'].' · '.$run['phase']).' · '.count($snapshot['nodes']).' nodes · '.(int)$run['token_input'].' input / '.(int)$run['token_output'].' output tokens confirmed'.($run['usage_unknown']?' · some usage unknown':'').'</p></div><div class="research-controls">';
            echo '<div data-stop-control'.(!$active&&!$pending?' hidden':'').'>'.self::form($csrf,'research_stop',(int)$run['id'],'Stop research','btn-outline-danger').'</div><div data-step-control'.(!$active||$busy?' hidden':'').'>'.self::form($csrf,'research_step',(int)$run['id'],'Continue next phase','btn-outline-secondary').'</div>';
            echo self::form($csrf,'research_export',(int)$run['id'],'Export Markdown','btn-outline-secondary').'</div></div><p data-research-reason>'.Ui::e($run['stop_reason']).'</p><p class="research-error" data-research-error role="alert"></p><p>Token counts update after each provider response; in-flight usage is not yet known. Stop prevents further phases and retains the tree; a provider call already in flight may still finish or be billed.</p><div class="research-subtabs"><a href="#investigate-'.(int)$run['id'].'">Investigate</a><a href="#learn-'.(int)$run['id'].'" data-learn-link'.(!$snapshot['sources']?' hidden':'').'>Learn</a></div><section id="investigate-'.(int)$run['id'].'"><h3>Investigate</h3><p>Dream: model’s prior thoughts — not yet checked. Evidence: provider-cited source cards. Reconciliation: supported, weakened, contradicted and still-open claims.</p><div data-research-tree>';self::nodes($snapshot['nodes']);
            echo '</div><h3>Collected sources</h3><p>Links come from completed provider web-search citations. Summaries are model paraphrases, not independently verified quotations.</p><div data-research-sources>';self::sources($snapshot['sources']);echo '</div><p data-source-empty'.($snapshot['sources']?' hidden':'').'>No source cards collected yet. Learn becomes available once evidence exists.</p></section><section id="learn-'.(int)$run['id'].'" data-learn-panel'.(!$snapshot['sources']?' hidden':'').'><h3>Learn</h3><p>Uses only this run’s collected cards and project brief. No new web search or shared Admin notes are sent. Available after the investigation stops.</p><form method="post" action="/loader.php?view=research" data-research-action="research_learn">'.$csrf.'<input type="hidden" name="action" value="research_learn"><input type="hidden" name="run_id" value="'.(int)$run['id'].'"><label class="form-label" for="learn-action">Learning action</label><select id="learn-action" class="form-select mb-3" name="learn_action"><option value="explain">Explain findings</option><option value="caveats">Caveats and limitations</option><option value="compare">Compare sources</option><option value="deeper">Go deeper</option><option value="check">Check my understanding</option></select><label class="form-label" for="learn-question">Question or your understanding (optional)</label><textarea id="learn-question" name="question" class="form-control mb-3" rows="3" maxlength="2000"></textarea><button class="btn btn-primary" data-learn-submit'.($active||$pending||!AgentRunner::configured()?' disabled':'').'>Learn from collected evidence</button></form><div data-learn-results role="region" aria-label="Saved learning responses">';
            foreach($snapshot['nodes'] as $learn)if($learn['type']==='learn')echo '<article class="research-message" data-learning-id="'.(int)$learn['id'].'"><h4>'.Ui::e($learn['title'].' · '.$learn['status']).'</h4><p>'.Ui::e($learn['content']).'</p></article>';
            echo '</div></section></div></section>';
        } else { echo '<div class="alert alert-info">No investigation yet. Start research to build a visible tree of unchecked claims, searches, source cards and findings.</div>'; }
        if($workspace['runs']){
            echo '<details class="card mb-3"><summary class="card-body">Investigation history</summary><div class="card-body">';
            foreach($workspace['runs'] as $old)echo '<p><a href="/loader.php?view=research&amp;project='.(int)$project['id'].'&amp;run='.(int)$old['id'].'">Run #'.(int)$old['id'].' · '.Ui::e($old['status']).' · '.Ui::e(Ui::time((int)$old['started_at'])).'</a></p>';
            echo '</div></details>';
        }
        echo '<details class="card"><summary class="card-body">Simple chat and earlier conversations</summary><div class="card-body"><p>Legacy project chat remains available. Investigations and Learn share its request limit.</p><div class="research-conversation" role="region" aria-label="Earlier research conversation">';
        foreach($data['messages'] as $message)if((int)$message['project_id']===(int)$project['id']){
            echo '<article class="research-message"><h3>'.($message['role']==='user'?'You':'Research assistant').' · '.Ui::e($message['status']).'</h3><p>'.Ui::e($message['text']?:'Waiting for the provider outcome.').'</p>';
            if($message['status']==='pending' && (int)$message['created_at']<time()-120)echo '<form method="post" action="/loader.php?view=research">'.$csrf.'<input type="hidden" name="action" value="research_resolve"><input type="hidden" name="message_id" value="'.(int)$message['id'].'"><button class="btn btn-outline-secondary">Mark interrupted request as uncertain</button></form>';
            echo '</article>';
        }
        echo '</div><form method="post" action="/loader.php?view=research" data-research-chat>'.$csrf.'<input type="hidden" name="action" value="research_chat"><input type="hidden" name="project_id" value="'.(int)$project['id'].'"><label class="form-label" for="simple-research-message">Message</label><textarea id="simple-research-message" name="message" class="form-control mb-3" rows="3" maxlength="4000" required></textarea><label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="web" value="1" checked><span class="form-check-label">Search the web for current sources (provider charges apply)</span></label><button class="btn btn-primary"'.(!AgentRunner::configured()?' disabled':'').'>Send research message</button><span class="ms-2" data-research-status role="status"></span></form></div></details>';
    }
}
