<?php
declare(strict_types=1);
namespace AIcontrol;

/** Native forms are the primary workflow; existing NASA JavaScript enhances them. */
final class NasaDisciplineView
{
    private static int $field=0;
    private static function e(mixed $value): string {return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function form(int $project,string $csrf,string $action,array $fields=[]): void
    {
        echo '<form method="post" action="/loader.php?view=nasa" data-nasa-form>'.$csrf;
        foreach(['action'=>$action,'nasa_project'=>$project]+$fields as $key=>$value)echo '<input type="hidden" name="'.self::e($key).'" value="'.self::e($value).'">';
    }
    private static function field(string $name,string $label,string $value='',int $max=160,bool $area=false): void
    {
        $id='nasa-discipline-'.++self::$field;
        echo '<label for="'.$id.'">'.self::e($label).'</label>';
        if($area)echo '<textarea class="form-control" id="'.$id.'" name="'.self::e($name).'" maxlength="'.$max.'" rows="3" required>'.self::e($value).'</textarea>';
        else echo '<input class="form-control" id="'.$id.'" name="'.self::e($name).'" value="'.self::e($value).'" maxlength="'.$max.'" required>';
    }
    private static function interfaceForm(int $project,string $csrf,?array $item): void
    {
        $data=$item['data']??[];
        self::form($project,$csrf,'nasa_save_record',['kind'=>'interface','item_key'=>$item['item_key']??'','version'=>$item['version']??0]);
        self::field('title','Interface name',$item['title']??'');
        self::field('content','Contract purpose and scope',$item['content']??'',3000,true);
        foreach(['side_a'=>['Side A',160,false],'side_b'=>['Side B',160,false],'payload'=>['Payload / schema / compatibility',2000,true],'errors'=>['Errors, timeouts and recovery',2000,true],'auth'=>['Authentication and authorisation',2000,true],'owner'=>['Interface owner',160,false],'change_rule'=>['Change rule / review agreement',1000,true]] as $key=>[$label,$max,$area])self::field($key,$label,$data[$key]??'',$max,$area);
        $field='nasa-discipline-'.++self::$field;echo '<label for="'.$field.'">Contract state</label><select class="form-select" id="'.$field.'" name="status"><option value="draft">Draft</option><option value="reviewed"'.(($item['status']??'')==='reviewed'?' selected':'').'>Reviewed by owner</option></select><button class="btn btn-primary">'.($item?'Save interface version':'Add interface contract').'</button></form>';
    }
    public static function interfaces(array $workspace,string $csrf): void
    {
        $project=(int)$workspace['project']['id'];$items=array_values(array_filter($workspace['items'],static fn($item)=>$item['kind']==='interface'));
        echo '<section class="nasa-interfaces" aria-label="Living interface contracts"><h3>Living interface contracts</h3><p>Record the agreement across each boundary: both sides, payload, failure behaviour, access and who must review changes. Handbook §§6.3–6.5; software adaptation.</p>';
        foreach($items as $item){echo '<article class="card"><div class="card-body"><h4>'.self::e($item['item_key'].' · '.$item['title']).'</h4><p>Version '.(int)$item['version'].' · '.self::e($item['status']).'</p><p>'.self::e($item['content']).'</p><dl>';foreach($item['data'] as $key=>$value)if(is_string($value))echo '<dt>'.self::e(ucfirst(str_replace('_',' ',$key))).'</dt><dd class="nasa-prewrap">'.self::e($value).'</dd>';echo '</dl><details><summary>Edit '.self::e($item['item_key']).' interface contract</summary>';self::interfaceForm($project,$csrf,$item);echo '</details>';self::form($project,$csrf,'nasa_archive_record',['kind'=>'interface','item_key'=>$item['item_key'],'version'=>$item['version']]);echo '<button class="btn btn-sm btn-outline-danger">Archive '.self::e($item['item_key']).'</button></form></div></article>';}
        echo '<details class="card"><summary>Add a living interface contract</summary><div class="card-body">';self::interfaceForm($project,$csrf,null);echo '</div></details></section>';
    }
    private static function delta(array $delta): void
    {
        $planning=$delta['planning'];echo '<p>Planning phase: '.self::e($delta['phase']['baseline']).' at baseline; '.self::e($delta['phase']['current']).' at comparison.</p><ul>';
        foreach(['added_since_baseline'=>'Added since baseline','missing_since_baseline'=>'Missing since baseline','changed_since_baseline'=>'Changed since baseline'] as $key=>$label){echo '<li>'.self::e($label).': '.count($planning[$key]);if($planning[$key]){echo '<ul>';foreach($planning[$key] as $item)echo '<li>'.self::e($item['item_key'].' · '.$item['title']).'</li>';echo '</ul>';}echo '</li>';}echo '</ul>';
        $research=$delta['research'];echo '<p><strong>Linked research:</strong> '.(!$research['baseline_recorded']?'Not captured by this older baseline.':($research['changed']?'Changed since baseline.':'Matches the captured content hash.')).'</p><p class="nasa-hint">Baseline SHA-256: '.self::e($research['baseline_sha256']??'not recorded').'<br>Current SHA-256: '.self::e($research['current_sha256']??'no linked research snapshot').'</p>';
        if($delta['provenance']['baseline_recorded'])echo '<p>Provenance links: '.(int)$delta['provenance']['baseline_count'].' at baseline; '.(int)$delta['provenance']['current_count'].' currently'.($delta['provenance']['changed']?' · changed.':'.').'</p>';
        echo '<p class="nasa-hint">Release/code SHA at baseline: '.self::e($delta['code']['baseline_sha']??'not available').'. Current: '.self::e($delta['code']['current_sha']??'not available').'.</p>';
    }
    public static function baselines(array $workspace,string $csrf): void
    {
        $project=(int)$workspace['project']['id'];$revision=(int)$workspace['project']['revision'];$reviewed=(bool)array_filter($workspace['reviews'],static fn($review)=>(int)$review['revision']===$revision&&$review['created_by']==='local');
        echo '<article class="card"><div class="card-body"><h3>Snapshots you can return to</h3><p>A baseline captures planning records, linked-research content hash, provenance and the installed code SHA when available. Record a local review of the current revision first.</p>';
        self::form($project,$csrf,'nasa_baseline',['revision'=>$revision]);self::field('title','Snapshot name');echo '<button class="btn btn-outline-primary"'.(!$reviewed?' disabled':'').'>Save planning baseline</button></form>';
        if(!$reviewed)echo '<p class="nasa-hint">A current local readiness review is required. AI advisory reviews cannot authorise a baseline.</p>';
        foreach($workspace['baselines'] as $baseline){echo '<details><summary>#'.(int)$baseline['id'].' · '.self::e($baseline['title']).'</summary><p class="nasa-hint">SHA-256 '.self::e($baseline['sha256']).'</p>';
            $delta=$workspace['baseline_deltas'][$baseline['id']]??null;$invalid=isset($delta['error']);
            if($invalid)echo '<p class="nasa-hint">'.self::e($delta['error']).'</p>';
            elseif($delta)self::delta($delta);
            echo '<p>Restore appends planning versions. Research, provenance reviews, code, tasks, servers and charges remain as recorded. Restored requirements become unverified until you explicitly confirm reviewed evidence again.</p>';
            self::form($project,$csrf,'nasa_restore_baseline',['baseline_id'=>$baseline['id'],'revision'=>$revision]);self::field('confirmation','Type RESTORE BASELINE '.$baseline['id'],'',80);echo '<button class="btn btn-outline-danger"'.($invalid?' disabled':'').'>Restore planning snapshot</button></form></details>';
        }
        if($workspace['baseline_restores']??[]){echo '<details><summary>Baseline restore history</summary>';foreach($workspace['baseline_restores'] as $restore){echo '<h4>Restore #'.(int)$restore['id'].' · baseline #'.(int)$restore['baseline_id'].'</h4><p>Appended revision '.(int)$restore['to_revision'].' from revision '.(int)$restore['from_revision'].'. Comparison captured before restoring:</p>';self::delta($restore['delta']);}echo '</details>';}
        echo '</div></article>';
    }
}
