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
    public static function claimSources(array $snapshot, int $claim): array
    {
        $parents=[]; foreach ($snapshot['nodes'] as $node) $parents[(int)$node['id']]=$node['parent_id'];
        return array_values(array_filter($snapshot['sources'], static function(array $source) use ($parents,$claim): bool {
            $meta=json_decode($source['meta_json']??'{}',true)??[];
            if (in_array($claim,array_map('intval',$meta['claim_ids']??[]),true)) return true;
            $id=(int)$source['node_id']; $seen=[];
            while ($id && !isset($seen[$id])) { if ($id===$claim) return true; $seen[$id]=true; $id=(int)($parents[$id]??0); }
            return false;
        }));
    }
    public static function board(array $snapshot): void
    {
        foreach ($snapshot['nodes'] as $claim) if ($claim['type']==='dream_claim') {
            $data=Investigation::claimData($claim);$id=(int)$claim['id'];
            echo '<article class="dream-card" data-claim-id="'.$id.'"><div class="claim-number">PRIOR / '.str_pad((string)$id,2,'0',STR_PAD_LEFT).'</div><label class="claim-selection"><input type="checkbox" name="claim_ids[]" value="'.$id.'"'.((int)($claim['selected']??1)?' checked':'').'><span>Include claim #'.$id.'</span></label><h4>'.Ui::e($data['claim']).'</h4><p class="claim-badges"><span class="claim-badge">'.Ui::e(ucfirst($data['confidence'])).' prior confidence</span>'.($data['priority']==='high'?'<span class="claim-badge">High priority</span>':'').'</p><p><strong>Uncertainty: </strong>'.Ui::e($data['uncertainty']).'</p><p><strong>Verify: </strong>'.Ui::e($data['verify']).'</p>';
            if (!(int)($claim['selected']??1)) echo '<p>Excluded from verification</p>';
            echo '<div class="claim-trail">';
            $descendants=[$id];foreach($snapshot['nodes'] as $node) if(in_array((int)$node['parent_id'],$descendants,true)) {$descendants[]=(int)$node['id'];if(in_array($node['type'],['plan_step','dead_end','synthesis'],true)) echo '<article class="claim-event" data-status="'.Ui::e($node['status']).'"><h5>'.Ui::e($node['title']).'</h5><p>'.Ui::e($node['type']==='synthesis'?(json_decode($node['data_json']??'{}',true)['reason']??$node['content']):$node['content']).'</p></article>';}
            foreach(self::claimSources($snapshot,$id) as $source) {echo '<p class="source-relevance">Evidence collected to test claim #'.$id.'</p>';self::sources([$source]);}
            echo '</div></article>';
        }
    }
    public static function matrix(array $snapshot): void
    {
        foreach($snapshot['nodes'] as $claim) if($claim['type']==='dream_claim') {
            $verdict=null;foreach($snapshot['nodes'] as $node)if((int)$node['parent_id']===(int)$claim['id']&&$node['type']==='synthesis')$verdict=$node;
            $data=json_decode($verdict['data_json']??'{}',true)??[];
            $outcome=$data['verdict']??($verdict?strtolower(explode(' · ',$verdict['title'])[0]):'open');
            if(!(int)($claim['selected']??1))$outcome='Excluded from verification';
            echo '<tr><th scope="row">'.Ui::e(Investigation::claimData($claim)['claim']).'</th><td>'.Ui::e(ucfirst($outcome)).'</td><td>';
            $sources=self::claimSources($snapshot,(int)$claim['id']);
            if(isset($data['source_ids']))$sources=array_filter($sources,static fn(array $source):bool=>in_array((int)$source['id'],$data['source_ids'],true));
            if(!$sources)echo 'No useful source';
            foreach($sources as $source)if(Investigation::sourceUrl($source['url']))echo '<a href="'.Ui::e($source['url']).'" target="_blank" rel="noopener noreferrer">'.Ui::e($source['title']).'</a> ';
            echo '</td></tr>';
        }
    }
    public static function render(array $data,string $csrf):void
    {
        $workspace=$data['workspace'];$project=$workspace['project'];$snapshot=$workspace['snapshot'];$run=$snapshot['run']??null;
        $review=$run && $run['status']==='running' && $run['phase']==='dream_review';
        $entry=$_SERVER['SCRIPT_NAME']??'/loader.php';
        if(!preg_match('~^/[A-Za-z0-9_./-]+\.php$~D',$entry))$entry='/loader.php';
        echo '<section class="card mb-3 research-project-picker"><div class="card-body"><div class="research-eyebrow">PROJECTS</div><div class="research-project-list">';
        foreach($data['projects'] as $p)echo '<a class="btn btn-outline-secondary" href="/loader.php?view=research&amp;project='.(int)$p['id'].'"'.($project && $p['id']===$project['id']?' aria-current="page"':'').'>'.Ui::e($p['name']).'</a>';
        echo '</div><details class="project-create"'.(!$project?' open':'').'><summary>Create a research project</summary><form method="post" action="/loader.php?view=research" class="mt-3">'.$csrf.'<input type="hidden" name="action" value="research_project"><label class="form-label" for="research-name">Research name</label><input id="research-name" name="name" class="form-control mb-3" maxlength="160" required><label class="form-label" for="research-brief">Research brief</label><textarea id="research-brief" name="brief" class="form-control mb-3" rows="3" maxlength="4000"></textarea><button class="btn btn-primary">Create research project</button></form></details></div></section>';
        if(!AgentRunner::configured())echo '<div class="alert alert-info">Connect OpenAI in <a href="/loader.php?view=admin">Admin</a> to start an investigation or chat. You can organise projects now.</div>';
        if(!$project)return;
        echo '<section class="card mb-3 research-project-brief"><div class="card-body"><h2>'.Ui::e($project['name']).'</h2><p class="text-break">'.Ui::e($project['brief']).'</p><p class="research-budget">Up to two verification searches · ten provider requests per hour</p><form method="post" action="/loader.php?view=research" data-research-action="research_start">'.$csrf.'<input type="hidden" name="action" value="research_start"><input type="hidden" name="project_id" value="'.(int)$project['id'].'"><button class="btn btn-primary"'.(!AgentRunner::configured()||($run&&in_array($run['status'],['pending','running'],true))?' disabled':'').'>Start research</button></form></div></section>';
        if($run){
            $active=in_array($run['status'],['pending','running'],true);$pending=(bool)array_filter($snapshot['requests'],static fn(array $r):bool=>$r['status']==='pending');$busy=$run['busy_since']!==null;
            echo '<section class="card mb-3 investigation" data-investigation data-review="'.($review?'1':'0').'" data-entry="'.Ui::e($entry).'" data-run-id="'.(int)$run['id'].'" data-status="'.Ui::e($run['status']).'" data-busy="'.($busy?'1':'0').'"><div class="card-body"><div class="investigation-heading"><div><div class="research-eyebrow">RESEARCH WORKSPACE</div><h2>Investigation #'.(int)$run['id'].'</h2><p data-research-summary role="status" aria-live="polite">'.Ui::e($run['status'].' · '.($review?'Dream complete — review claims':$run['phase'])).' · '.(int)$run['token_input'].' input / '.(int)$run['token_output'].' output tokens confirmed'.($run['usage_unknown']?' · some usage unknown':'').'</p></div><div class="research-controls">';
            echo '<div data-stop-control'.(!$active&&!$pending?' hidden':'').'>'.self::form($csrf,'research_stop',(int)$run['id'],'Stop research','btn-outline-danger').'</div><div data-step-control'.(!$active||$busy||$review?' hidden':'').'>'.self::form($csrf,'research_step',(int)$run['id'],'Continue next phase','btn-outline-secondary').'</div>';
            echo self::form($csrf,'research_export',(int)$run['id'],'Export Markdown','btn-outline-secondary').'</div></div><p data-research-reason>'.Ui::e($run['stop_reason']).'</p><p class="research-error" data-research-error role="alert"></p><details class="research-run-notes"><summary>Usage &amp; Stop behavior</summary><p>Tokens are confirmed after each response. Stop prevents the next phase; an in-flight call may finish or be billed. Saved evidence remains available.</p></details><ol class="research-phase-rail" aria-label="Investigation stages" data-phase-rail>';
            foreach(['dream'=>'01 · Dream','dream_review'=>'02 · Review','plan'=>'03 · Plan','evidence'=>'04 · Evidence','reconciliation'=>'05 · Findings'] as $phase=>$label)echo '<li'.($run['phase']===$phase?' aria-current="step"':'').'>'.Ui::e($label).'</li>';
            echo '</ol><div class="research-metrics" data-research-metrics><div><strong>'.count(array_filter($snapshot['nodes'],static fn(array $n):bool=>$n['type']==='dream_claim')).'</strong><span>prior claims</span></div><div><strong>'.count($snapshot['sources']).'</strong><span>collected sources</span></div><div><strong>'.Ui::e($run['status']).'</strong><span>run status</span></div></div><div class="research-subtabs"><a href="#investigate-'.(int)$run['id'].'">Investigate</a><a href="#learn-'.(int)$run['id'].'" data-learn-link'.(!$snapshot['sources']?' hidden':'').'>Learn</a></div><section id="investigate-'.(int)$run['id'].'"><div class="research-section-heading"><span class="research-eyebrow">CLAIMS &amp; EVIDENCE</span><p>Each claim starts as a hypothesis. Sources and findings appear beneath the claim they test.</p></div><section class="dream-board" aria-label="Dream claim board"><h3>Model’s prior — not yet checked</h3><p class="dream-empty" data-dream-empty'.(array_filter($snapshot['nodes'],static fn(array $n):bool=>$n['type']==='dream_claim')?' hidden':'').'>The prior will appear here after Dream finishes. No evidence has been established yet.</p><p data-review-copy'.(!$review?' hidden':'').'>Choose what to verify. Uncheck weak claims, then continue. Up to two searches follow.</p><form method="post" action="/loader.php?view=research" data-research-action="research_continue">'.$csrf.'<input type="hidden" name="action" value="research_continue"><input type="hidden" name="run_id" value="'.(int)$run['id'].'"><div class="dream-grid" data-dream-cards>';self::board($snapshot);
            echo '</div><div class="claim-review-actions"><span data-selection-count></span><button class="btn btn-primary" data-continue-claims'.(!$review?' hidden':'').'>Continue with selected claims</button></div><noscript><p>Continue uses all claims when JavaScript is unavailable.</p></noscript></form><div class="prior-context" data-prior-context>';
            foreach($snapshot['nodes'] as $node){$context=json_decode($node['data_json']??'{}',true);if(isset($context['least_confident']))echo '<h4>Prior context</h4><p><strong>Competing angles: </strong>'.Ui::e(implode('; ',$context['competing_angles'])).'</p><p><strong>Least confident: </strong>'.Ui::e($context['least_confident']).'</p><p><strong>Background assumptions: </strong>'.Ui::e(implode('; ',$context['background_assumptions'])).'</p>';}
            echo '</div></section><section data-claim-matrix'.($run['status']!=='complete'?' hidden':'').'><h3>Claim outcomes</h3><div class="claim-matrix-scroll"><table class="table"><thead><tr><th scope="col">Claim</th><th scope="col">Outcome</th><th scope="col">Key sources</th></tr></thead><tbody data-matrix-rows>';self::matrix($snapshot);echo '</tbody></table></div></section><details class="full-research-tree"><summary>Full investigation tree</summary><div data-research-tree>';self::nodes($snapshot['nodes']);
            echo '</div></details><details class="source-catalog"><summary>Source library</summary><p>Links come from completed provider web-search citations. Summaries are model paraphrases, not independently verified quotations.</p><div data-research-sources>';self::sources($snapshot['sources']);echo '</div></details><p data-source-empty'.($snapshot['sources']?' hidden':'').'>No source cards collected yet. Learn becomes available once evidence exists.</p></section><section id="learn-'.(int)$run['id'].'" data-learn-panel'.(!$snapshot['sources']?' hidden':'').'><h3>Learn</h3><p>Uses only this run’s collected cards and project brief. No new web search or shared Admin notes are sent. Available after the investigation stops.</p><form method="post" action="/loader.php?view=research" data-research-action="research_learn">'.$csrf.'<input type="hidden" name="action" value="research_learn"><input type="hidden" name="run_id" value="'.(int)$run['id'].'"><label class="form-label" for="learn-action">Learning action</label><select id="learn-action" class="form-select mb-3" name="learn_action"><option value="explain">Explain findings</option><option value="caveats">Caveats and limitations</option><option value="compare">Compare sources</option><option value="deeper">Go deeper</option><option value="check">Check my understanding</option></select><label class="form-label" for="learn-question">Question or your understanding (optional)</label><textarea id="learn-question" name="question" class="form-control mb-3" rows="3" maxlength="2000"></textarea><button class="btn btn-primary" data-learn-submit'.($active||$pending||!AgentRunner::configured()?' disabled':'').'>Learn from collected evidence</button></form><div data-learn-results role="region" aria-label="Saved learning responses">';
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
