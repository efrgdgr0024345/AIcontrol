<?php
declare(strict_types=1);
namespace AIcontrol;

/** Ordinary owner forms first; existing NASA progressive enhancement is optional. */
final class NasaCollaborationView
{
    private static int $field=0;
    private static function e(mixed $value): string {return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function hidden(string $name,mixed $value): void {echo '<input type="hidden" name="'.self::e($name).'" value="'.self::e($value).'">';}
    private static function form(string $entry,string $csrf,int $project,string $action,array $fields=[],bool $ai=false): void
    {
        echo '<form method="post" action="'.self::e($entry.'?view=nasa').'" data-nasa-form'.($ai?' data-nasa-ai':'').'>'.$csrf;
        foreach(['action'=>$action,'nasa_project'=>$project]+$fields as $key=>$value)self::hidden($key,$value);
    }
    private static function input(string $name,string $label,mixed $value,int $max=160,bool $area=false): void
    {
        $id='nasa-proposal-field-'.++self::$field;echo '<label for="'.$id.'">'.self::e($label).'</label>';
        if($area)echo '<textarea class="form-control" id="'.$id.'" name="'.self::e($name).'" maxlength="'.$max.'" rows="4" required>'.self::e($value).'</textarea>';
        else echo '<input class="form-control" id="'.$id.'" name="'.self::e($name).'" maxlength="'.$max.'" value="'.self::e($value).'" required>';
    }
    private static function select(string $name,string $label,mixed $value,array $options): void
    {
        $id='nasa-proposal-field-'.++self::$field;echo '<label for="'.$id.'">'.self::e($label).'</label><select class="form-select" id="'.$id.'" name="'.self::e($name).'">';
        foreach($options as $key=>$text)echo '<option value="'.self::e($key).'"'.((string)$key===(string)$value?' selected':'').'>'.self::e($text).'</option>';
        echo '</select>';
    }
    private static function move(array $move,string $label): void
    {
        $process=NasaKnowledge::processes()[$move['process']][0]??$move['process'];
        echo '<h4>'.self::e($label.' · '.$move['title']).'</h4><p>'.self::e($move['why']).'</p><p><strong>Next step:</strong> '.self::e($move['next_step']).'</p><p class="nasa-hint">Handbook §'.self::e($move['process']).' · '.self::e($process).'</p>';
    }
    private static function preserveAdvice(array $payload): void
    {
        foreach($payload['primary'] as $key=>$value)self::hidden('proposal[primary]['.$key.']',$value);
        foreach($payload['alternatives'] as $i=>$move)foreach($move as $key=>$value)self::hidden('proposal[alternatives]['.$i.']['.$key.']',$value);
        foreach(['assumptions','unknowns'] as $kind)foreach($payload[$kind] as $i=>$value)self::hidden('proposal['.$kind.']['.$i.']',$value);
    }
    private static function editBundle(array $payload): void
    {
        self::preserveAdvice($payload);
        foreach($payload['bundle']['artifacts'] as $i=>$artifact){$prefix='proposal[bundle][artifacts]['.$i.']';self::hidden($prefix.'[type]',$artifact['type']);self::input($prefix.'[content]',NasaKnowledge::ARTIFACTS[$artifact['type']][0].' proposed draft',$artifact['content'],3000,true);}
        foreach($payload['bundle']['requirements'] as $i=>$requirement){$prefix='proposal[bundle][requirements]['.$i.']';echo '<fieldset><legend>Proposed requirement '.($i+1).'</legend>';self::input($prefix.'[title]','Requirement title',$requirement['title'],40);self::input($prefix.'[statement]','Testable shall statement',$requirement['statement'],700,true);self::input($prefix.'[source]','Source need or assumption',$requirement['source'],250);self::select($prefix.'[method]','Proposed verification method',$requirement['method'],array_combine(['test','analysis','inspection','demonstration'],['Test','Analysis','Inspection','Demonstration']));echo '</fieldset>';}
        foreach($payload['bundle']['risks'] as $i=>$risk){$prefix='proposal[bundle][risks]['.$i.']';echo '<fieldset><legend>Proposed risk '.($i+1).'</legend>';self::input($prefix.'[title]','Risk title',$risk['title'],40);self::input($prefix.'[condition]','Uncertain condition and consequence',$risk['condition'],700,true);self::input($prefix.'[owner]','Proposed risk owner',$risk['owner'],40);self::input($prefix.'[mitigation]','Proposed response, not yet implemented',$risk['mitigation'],500,true);foreach(['likelihood'=>'Initial likelihood','consequence'=>'Initial impact'] as $key=>$label)self::select($prefix.'['.$key.']',$label,$risk[$key],[1=>'1',2=>'2',3=>'3',4=>'4',5=>'5']);echo '</fieldset>';}
    }
    public static function render(array $workspace,string $csrf,string $entry='/loader.php'): void
    {
        $project=(int)$workspace['project']['id'];self::$field=0;$configured=AgentRunner::configured();
        echo '<section id="nasa-collaboration"><article class="card"><div class="card-body"><h3>Choose your next engineering move</h3><p>Ask for one recommended move, alternatives, assumptions and unknowns. An optional bundle stays here until you edit, accept or reject it.</p><div class="nasa-package-actions">';
        self::form($entry,$csrf,$project,'nasa_collaborate',[],true);echo '<button class="btn btn-outline-primary"'.(!$configured?' disabled':'').'>Ask for next moves</button></form></div><details><summary>Propose a coherent draft bundle</summary><p>Choose one to three documents. Related requirements and risks are proposed only when their document is selected.</p>';
        self::form($entry,$csrf,$project,'nasa_collaborate',[],true);echo '<fieldset><legend>Documents to propose together</legend>';
        foreach(NasaKnowledge::ARTIFACTS as $key=>$artifact){$id='nasa-proposal-type-'.$key;echo '<label for="'.$id.'"><input id="'.$id.'" type="checkbox" name="artifacts[]" value="'.self::e($key).'"> '.self::e($artifact[0]).'</label>';}
        echo '</fieldset><button class="btn btn-outline-primary"'.(!$configured?' disabled':'').'>Propose selected draft bundle</button></form></details><p class="nasa-hint">Each request shares the existing eight-per-hour workspace limit and $1 USD per source-project rolling-day allowance. Advice cannot verify requirements, accept risk, advance a phase or create a baseline.</p></div></article>';
        foreach($workspace['proposals']??[] as $proposal){
            $payload=$proposal['payload'];$pending=$proposal['status']==='pending';$fresh=$proposal['fresh']??false;$fields=['proposal_key'=>$proposal['proposal_key'],'version'=>$proposal['version']];
            echo '<article class="card nasa-proposal">';
            if($pending)echo '<div class="card-body">';
            else echo '<details class="card-body"><summary>'.self::e($proposal['proposal_key']).' · version '.(int)$proposal['version'].' · '.self::e(ucfirst($proposal['status'])).'</summary><div>';
            echo '<h3>'.self::e($proposal['proposal_key']).' · version '.(int)$proposal['version'].'</h3><p class="nasa-hint">'.self::e(ucfirst($proposal['status'])).' · AI request #'.(int)$proposal['request_id'].' · '.($pending?'No project records have been applied.':'Owner disposition recorded; earlier proposal versions are retained.').'</p>';
            if($pending&&!$fresh)echo '<p role="status">Planning or research context changed. Request fresh advice or reject this proposal; it cannot be accepted or edited.</p>';
            self::move($payload['primary'],'Recommended');echo '<details><summary>Other useful moves</summary>';foreach($payload['alternatives'] as $move)self::move($move,'Alternative');echo '</details>';
            foreach(['assumptions'=>'Assumptions to confirm','unknowns'=>'Unknowns to investigate'] as $key=>$title){echo '<h4>'.self::e($title).'</h4>';if(!$payload[$key])echo '<p>None recorded in this proposal.</p>';else{echo '<ul>';foreach($payload[$key] as $text)echo '<li>'.self::e($text).'</li>';echo '</ul>';}}
            $bundle=$payload['bundle'];echo '<p>'.count($bundle['artifacts']).' proposed documents · '.count($bundle['requirements']).' requirements · '.count($bundle['risks']).' risks.</p>';
            if($bundle['artifacts']){
                echo '<details><summary>'.($pending&&$fresh?'Read and edit proposed bundle':'Read proposed bundle').'</summary>';
                if($pending&&$fresh){self::form($entry,$csrf,$project,'nasa_proposal_edit',$fields);self::editBundle($payload);echo '<p class="nasa-hint">Saving an edit appends a proposal version. It does not change project records. Accept the saved version separately.</p><button class="btn btn-outline-primary">Save edited proposal</button></form>';}
                else{foreach($bundle['artifacts'] as $artifact)echo '<h4>'.self::e(NasaKnowledge::ARTIFACTS[$artifact['type']][0]).'</h4><p class="nasa-prewrap">'.self::e($artifact['content']).'</p>';foreach($bundle['requirements'] as $requirement)echo '<h4>'.self::e($requirement['title']).'</h4><p>'.self::e($requirement['statement']).'</p>';foreach($bundle['risks'] as $risk)echo '<h4>'.self::e($risk['title']).'</h4><p>'.self::e($risk['condition']).'</p>';}
                echo '</details>';
            }
            if($pending){echo '<div class="nasa-package-actions">';if($bundle['artifacts']){self::form($entry,$csrf,$project,'nasa_proposal_accept',$fields);echo '<button class="btn btn-primary"'.(!$fresh?' disabled':'').'>Accept saved bundle as drafts</button></form>';}self::form($entry,$csrf,$project,'nasa_proposal_reject',$fields);echo '<button class="btn btn-outline-danger">Reject proposal</button></form></div>';}
            if($proposal['status']==='accepted'){echo '<h4>Records created together</h4><ul>';foreach($proposal['disposition']['records']??[] as $record)echo '<li>'.self::e($record['item_key']).' · version '.(int)$record['version'].' · '.self::e($record['kind']).'</li>';echo '</ul>';}
            echo '</div>'.($pending?'':'</details>').'</article>';
        }
        echo '</section>';
    }
}
