<?php
declare(strict_types=1);
namespace AIcontrol;
use AIcontrolWorkspaceUi as Ui;

/** Owner forms remain useful with JavaScript disabled. */
final class NasaIntegrationView
{
    private static int $field=0;
    private static function e(mixed $v): string {return Ui::e((string)$v);}
    private static function form(array $s,string $csrf,string $action,array $fields=[]): void
    {
        echo '<form method="post" action="/loader.php?view=nasa" data-nasa-form>'.$csrf;
        foreach(['action'=>$action,'nasa_project'=>$s['project']['id']]+$fields as $k=>$v)echo '<input type="hidden" name="'.self::e($k).'" value="'.self::e($v).'">';
    }
    private static function field(string $name,string $label,string $value='',int $max=1000,bool $required=true): void
    {
        $id='nasa-link-field-'.++self::$field;echo '<label for="'.$id.'">'.self::e($label).'</label><textarea class="form-control" id="'.$id.'" name="'.self::e($name).'" maxlength="'.$max.'" rows="2"'.($required?' required':'').'>'.self::e($value).'</textarea>';
    }
    private static function choices(array $s,string $name,string $label,bool $manual=false): void
    {
        $id='nasa-link-field-'.++self::$field;echo '<label for="'.$id.'">'.self::e($label).'</label><select class="form-select" name="'.self::e($name).'" id="'.$id.'">';
        if($manual)echo '<option value="manual_evidence:0:0">Manual evidence record</option>';
        foreach($s['research_choices']??[] as $c){$token=$c['source_kind'].':'.$c['run_id'].':'.$c['source_id'];$selected=(string)($_GET['promote_run']??'')===(string)$c['run_id']&&(string)($_GET['promote_node']??'')===(string)$c['source_id']&&$c['source_kind']==='research_node';echo '<option value="'.self::e($token).'"'.($selected?' selected':'').'>'.self::e($c['title'].' · '.$c['source_kind'].' #'.$c['source_id'].' · run '.$c['run_id']).'</option>';}
        echo '</select>';
    }
    public static function promote(array $s,string $csrf): void
    {
        echo '<article class="card"><div class="card-body"><h3>Research into engineering planning</h3><p>Promote a saved idea or finding into a new draft version. The original research and its provenance stay intact.</p>';
        if(!($s['research_choices']??[])){echo '<p>Link a research project with saved ideas or evidence to promote a record.</p></div></article>';return;}
        self::form($s,$csrf,'nasa_promote_research');self::choices($s,'research_source','Saved research idea / finding');
        $id='nasa-link-field-'.++self::$field;echo '<label for="'.$id.'">Planning record type</label><select class="form-select" id="'.$id.'" name="target_type"><option value="requirement">Requirement — unverified</option><option value="risk">Risk — open</option><option value="decision">Decision — proposed</option>';foreach(NasaKnowledge::ARTIFACTS as $key=>$a)echo '<option value="artifact:'.$key.'">'.self::e($a[0]).' — draft</option>';echo '</select>';
        self::field('target_version','Expected current version (0 for a new record)','0',10);self::field('target_key','Existing structured record ID to update (optional)','',40,false);self::field('title','Draft title override (optional)','',160,false);self::field('content','Draft text override (optional)','',3000,false);
        $id='nasa-link-field-'.++self::$field;echo '<label for="'.$id.'">Requirement verification method</label><select class="form-select" id="'.$id.'" name="method">';foreach(['test','analysis','inspection','demonstration'] as $m)echo '<option>'.$m.'</option>';echo '</select>';
        self::field('note','Provenance / promotion note','Seeded from selected research; owner review required.',1000);echo '<button class="btn btn-outline-primary">Promote research as draft</button></form></div></article>';
    }
    public static function links(array $s,array $item,string $csrf): void
    {
        $links=array_values(array_filter($s['provenance']??[],static fn($l)=>($l['kind']??$l['item_kind']??'')===$item['kind']&&$l['item_key']===$item['item_key']));
        echo '<details class="nasa-provenance"><summary>Linked research &amp; evidence · '.count($links).'</summary><p class="nasa-hint">Links retain the exact source snapshot and planning version. Only reviewed evidence matching this requirement can support owner-confirmed verification.</p>';
        foreach($links as $l){$snapshot=$l['snapshot']??$l['source_snapshot']??[];echo '<article><h5>'.self::e(($snapshot['title']??$l['source_kind']).' · link #'.$l['id'].' v'.$l['version']).'</h5><p>'.self::e($l['source_kind'].' · source #'.$l['source_id'].' · '.($l['reviewed']??false?'Reviewed by owner':'Not owner-reviewed')).'</p><p>'.self::e($l['note']??'').'</p><details><summary>Captured source and provenance</summary><pre class="nasa-prewrap">'.self::e(json_encode($snapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)).'</pre></details>';
            self::form($s,$csrf,'nasa_review_provenance',['link_id'=>$l['id'],'link_version'=>$l['version'],'version'=>$item['version']]);self::field('review_note','Owner evidence review note','',1000);echo '<button class="btn btn-sm btn-outline-primary">Record owner evidence review</button></form></article>';
        }
        self::form($s,$csrf,'nasa_attach_provenance',['kind'=>$item['kind'],'item_key'=>$item['item_key'],'version'=>$item['version']]);self::choices($s,'research_source','Evidence or research source',true);self::field('note','Why this source is linked','',1000);
        echo '<details><summary>Manual evidence fields (only for Manual evidence record)</summary>';self::field('manual_title','Evidence title','',160,false);self::field('manual_content','Observed result / evidence excerpt','',3000,false);self::field('manual_reference','Test output, file, URL or reproducible record reference','',1000,false);
        $id='nasa-link-field-'.++self::$field;echo '<label for="'.$id.'">Evidence method</label><select class="form-select" name="manual_method" id="'.$id.'">';foreach(['test','analysis','inspection','demonstration'] as $method)echo '<option>'.$method.'</option>';echo '</select></details><button class="btn btn-sm btn-outline-secondary">Attach source for review</button></form></details>';
    }
    public static function spawn(array $s,string $csrf,string $key,string $label,int $version=0): void
    {
        self::form($s,$csrf,'nasa_focused_research',['focus_key'=>$key,'focus_version'=>$version,'revision'=>$s['project']['revision']]);
        $id='nasa-link-field-'.++self::$field;echo '<label for="'.$id.'">Research destination for '.self::e($label).'</label><select class="form-select" name="run_id" id="'.$id.'"><option value="0">New focused research project</option>';foreach($s['map_runs']??[] as $run)echo '<option value="'.(int)$run['id'].'">Continue linked investigation #'.(int)$run['id'].'</option>';echo '</select><button class="btn btn-sm btn-outline-secondary">Spawn focused research exploration</button></form>';
    }
}
