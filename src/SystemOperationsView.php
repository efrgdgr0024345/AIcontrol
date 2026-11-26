<?php
declare(strict_types=1);
namespace AIcontrol;
use AIcontrolWorkspaceUi as Ui;
require_once __DIR__.'/SystemPlaybooks.php';
require_once __DIR__.'/SystemActionGuide.php';
final class SystemOperationsView
{
 private static function form(string $csrf,string $action,int $id=0,array $extra=[]):void{echo '<form method="post" enctype="multipart/form-data" action="/loader.php?view=systems" class="systems-form">'.$csrf.'<input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="system_id" value="'.$id.'">';foreach($extra as $key=>$value)echo '<input type="hidden" name="'.Ui::e($key).'" value="'.Ui::e($value).'">';}
 public static function guard(array $state,string $csrf,int $id=0):void{
  echo '<section id="systems-protection" class="card mb-3 systems-privacy" data-system-privacy data-unlock-until="'.(int)$state['until'].'"><div class="card-body"><h2>'.Ui::icon('lock').' Systems protection</h2>';
  if($state['unlocked']){echo '<p>Remote control unlocked for 15 minutes. Protected note values remain masked until explicitly revealed. Connection details and setup commands are available while unlocked. Lock prevents new dispatch; already-approved operations may finish.</p>';self::form($csrf,'system_guard_lock',$id);echo '<button class="btn btn-outline-secondary" data-systems-lock>Lock Systems now</button></form>';echo '<details><summary>Change Systems password</summary>';self::form($csrf,'system_guard_change',$id);foreach([['systems_current_password','Current Systems password','current-password'],['systems_new_password','New Systems password','new-password'],['systems_new_confirm','Confirm new Systems password','new-password']] as [$name,$label,$complete])echo '<label class="form-label" for="'.$name.'">'.$label.'</label><input class="form-control" id="'.$name.'" name="'.$name.'" type="password" autocomplete="'.$complete.'" maxlength="256" required>';echo '<button class="btn btn-outline-secondary">Change Systems password</button></form></details>';}
  else{echo '<p>Readable notes are available below. SSH control and protected records require a separate Systems password. The website PIN cannot replace an existing Systems password.</p>';self::form($csrf,$state['configured']?'system_guard_unlock':'system_guard_setup',$id);echo '<label class="form-label" for="systems-password">Systems password</label><input class="form-control" id="systems-password" name="systems_password" type="password" '.($state['configured']?'autocomplete="current-password"':'autocomplete="new-password" minlength="12"').' maxlength="256" required>';
   if(!$state['configured'])echo '<label class="form-label" for="systems-password-confirm">Confirm Systems password</label><input class="form-control" id="systems-password-confirm" name="systems_password_confirm" type="password" autocomplete="new-password" minlength="12" maxlength="256" required><p>Use a unique passphrase of at least 12 characters, including letters. Keep it outside this website. Set it while you trust your owner sign-in; there is no PIN-only password reset.</p>';
   echo '<button class="btn btn-primary">'.($state['configured']?'Unlock Systems':'Set Systems password').'</button></form>';}
  echo '</div></section>';
 }
 public static function locked(array $data,string $csrf):void{
  echo '<section class="card mb-3"><div class="card-body"><h2>Linux servers</h2><p>Unlock Systems to connect servers, run commands or manage their access.</p><ul class="systems-list">';foreach($data['systems'] as $s)echo '<li><a href="/loader.php?view=systems&amp;system='.(int)$s['id'].'">'.Ui::e($s['name']).'</a> · connection details ********</li>';echo '</ul></div></section>';
  if($data['selected']){self::actions($data['selected'],$data,$csrf,false);self::notes($data['selected'],$data,$csrf,false);}
 }
 public static function notes(array $s,array $data,string $csrf,bool $unlocked):void{
  $id=(int)$s['id'];$note=$data['notebook']??['notes'=>'','protected'=>[],'observations'=>[]];
  echo '<section class="card mb-3"><div class="card-body"><h2>VPS notes</h2><p>Private values are encrypted at rest and displayed as ********. Put usernames, passwords and tokens in protected fields. Automatic masking catches known values and common credential formats; check your public notes before saving.</p><div class="system-public-notes">'.nl2br(Ui::e($note['notes']?:'No public notes saved yet.')).'</div>';
  if($note['inventory']??[]){echo '<h3>Last observed inventory</h3><dl class="system-inventory">';foreach($note['inventory'] as $key=>$value)echo '<dt>'.Ui::e(ucfirst(str_replace('_',' ',$key))).'</dt><dd>'.Ui::e(is_array($value)?implode(', ',$value):(is_bool($value)?($value?'Yes':'No'):(string)$value)).'</dd>';echo '</dl><p>These are saved observations. Run Check system setup to refresh; backup files do not establish a recoverable backup.</p>';}
  if($note['protected']){echo '<dl>';foreach($note['protected'] as $label=>$value)echo '<dt>'.Ui::e($label).'</dt><dd>********</dd>';echo '</dl>';}
  if($unlocked){self::form($csrf,'system_notes_save',$id);echo '<label class="form-label" for="vps-notes">Readable notes</label><textarea class="form-control" id="vps-notes" name="notes" rows="4" maxlength="4000">'.Ui::e($note['notes']).'</textarea><details><summary>Add or replace a protected value</summary><label class="form-label" for="vps-secret-label">Protected label (for example database_login)</label><input class="form-control" id="vps-secret-label" name="secret_label" pattern="[a-z][a-z0-9_-]{0,47}" maxlength="48"><label class="form-label" for="vps-secret-value">Protected value</label><input class="form-control" id="vps-secret-value" type="password" name="secret_value" maxlength="4000" autocomplete="off"></details><button class="btn btn-primary">Save VPS notes</button></form>';
   self::form($csrf,'system_notes_reveal',$id);echo '<button type="submit" class="btn btn-outline-secondary" data-protected-reveal>Reveal protected values and full observations</button><pre class="systems-code" data-protected-output hidden></pre><p role="status" data-protected-status></p></form>';}
  if($note['observations']){echo '<h3>Observed changes</h3><ol class="system-note-events">';foreach(array_slice($note['observations'],0,20) as $e)echo '<li><strong>'.Ui::e($e['title']).'</strong> · '.Ui::e($e['status']).'<small>'.Ui::e($e['utc']).'</small></li>';echo '</ol><p>Latest 100 notebook observations are retained; guided operation records remain in action history.</p>';}
  echo '</div></section>';
 }
 public static function actions(array $system,array $data,string $csrf,bool $unlocked):void
 {
  $id=(int)$system['id'];
  $latest=$data['latest_operations']??[];
  $check=$latest['inspect']??null;
  $checks=$check['data']['inventory']['setup_checks']??[];
  echo '<section class="system-action-controls" id="server-actions" aria-labelledby="server-actions-title"><h3 id="server-actions-title">Server actions</h3>';
  if($unlocked&&$system['enabled']&&$system['verified_at']){
   $aiBlocked=match(true){
    !empty($data['ssh_blocked'])=>'Reconcile the active server action first.',
    !AgentRunner::configured()=>'Connect OpenAI in Admin to use this action.',
    default=>null,
   };
   $disabled=$aiBlocked===null?'':' disabled';
   self::form($csrf,'system_playbook_ai_prepare',$id);
   echo '<label class="form-label" for="setup-goal">What should AI set up?</label><input class="form-control" id="setup-goal" name="setup_goal" maxlength="1500" placeholder="For example, set up private VPN access" required'.$disabled.'><p>'.Ui::e($aiBlocked??'AI chooses one supported action. Server changes still require review and approval.').'</p><button class="btn btn-primary"'.$disabled.'>Ask AI to prepare</button></form>';
  }
  if($check)echo '<p role="status">Last setup check '.Ui::e(Ui::time(gmdate('c',(int)$check['updated_at']))).'. '.($checks?'Run it again to refresh the detected state.':'No installation states were confirmed in this check.').'</p>';
  foreach(SystemPlaybooks::RECIPES as $recipe=>$definition){
   $state=$checks[$recipe]??null;
   $detected=self::detectedState($recipe,$state);
   $blocked=match(true){
    !$unlocked=>'Unlock Systems first.',
    !$system['enabled']||!$system['verified_at']=>'Verify the connection first.',
    !empty($data['ssh_blocked'])=>'Reconcile the active server action first.',
    $definition['access']==='admin'&&$system['risk_level']!=='admin'=>'Administrator enrollment required.',
    $definition['access']==='account'&&$system['access']!=='maintain'=>'Account-level shell access required.',
    default=>null,
   };
   $allowed=$blocked===null&&!SystemPlaybooks::alreadyConfigured($recipe,$state);
   $label=$definition['access']==='inspect'?$definition['title']:'Review '.$definition['title'];
   echo '<article class="system-action-card'.($recipe==='inspect'?' system-setup-check':'').'" data-recipe="'.Ui::e($recipe).'">';
   if($recipe!=='inspect')echo '<h4>'.Ui::e($definition['title']).'</h4>';
   echo '<p>'.Ui::e($definition['description']).'</p>';
   self::form($csrf,'system_playbook_prepare',$id,['recipe'=>$recipe]);
   echo '<button class="btn '.($recipe==='inspect'?'btn-primary btn-lg':'btn-outline-primary').'"'.(!$allowed?' disabled':'').'>'.Ui::e($label).'</button></form>';
   $status=$detected??$blocked;
   if($status===null&&$check)$status=match($state){'absent'=>'No matching setup detected at the last check.','unknown'=>'Could not verify this item at the last check.',default=>null};
   if($status)echo '<small data-setup-status>'.Ui::e($status).'</small>';
   SystemActionGuide::render($recipe,$latest[$recipe]??null);
   echo '</article>';
   if($recipe==='inspect')echo '<div class="system-action-grid">';
  }
  echo '</div></section>';
  if(!$unlocked)return;
  $selected=$data['playbook']??null;if($selected)self::operation($selected,$csrf);
  if($data['playbooks']??[]){echo '<section class="card mb-3"><div class="card-body"><h2>Guided action history</h2><ul>';foreach($data['playbooks'] as $r)echo '<li><a class="text-decoration-underline" href="/loader.php?view=systems&amp;system='.$id.'&amp;operation='.Ui::e($r['id']).'#server-operation">'.Ui::e(SystemPlaybooks::RECIPES[$r['recipe']]['title']).'</a> · '.Ui::e($r['status']).' · '.Ui::e(gmdate('c',(int)$r['updated_at'])).'</li>';echo '</ul></div></section>';}
 }
 private static function detectedState(string $recipe,mixed $state):?string
 {
  return match($recipe){
   'vpn'=>match($state){'active'=>'WireGuard interface active. Client handshake still needs a separate check.','configured'=>'A VPN configuration or interface already exists. Review it before making changes.',default=>null},
   'firewall'=>$state==='configured'?'Existing firewall rules detected. This action is for a fresh firewall.':null,
   'codex'=>match($state){'installed'=>'Codex executable detected for this Linux account.','configured'=>'The Codex install path is already occupied.',default=>null},
   'security_updates'=>match($state){'active'=>'AIcontrol automatic-update policy and timers confirmed.','configured'=>'An automatic-update policy exists. Its settings or timers could not be confirmed.',default=>null},
   default=>null,
  };
 }
 private static function operation(array $r,string $csrf):void{
  $id=(int)$r['system_id'];$p=$r['data'];$title=SystemPlaybooks::RECIPES[$r['recipe']]['title'];$extra=['operation_id'=>$r['id']];echo '<section id="server-operation" class="card mb-3" data-playbook="'.Ui::e($r['id']).'" data-playbook-active="'.($r['blocking']?'1':'0').'" data-playbook-state="'.Ui::e($r['status']).'"><div class="card-body"><h2>'.Ui::e($title).'</h2><p role="status" data-playbook-status>'.Ui::e($r['status']).'</p>';
  if($r['status']==='review'){
   echo '<p>'.Ui::e(SystemPlaybooks::RECIPES[$r['recipe']]['description']).'</p><p>Plan expires ten minutes after preparation. Confirm an off-server backup or provider snapshot before updates. Recovery-console access is required before changes. No automatic reboot, SSH configuration rewrite, server-password retrieval or model-generated root command is included.</p>';
   if($r['recipe']==='codex')echo '<p>Official OpenAI release 0.171.0, verified archive SHA-256 <code>'.Ui::e($p['parameters']['asset']['sha256']).'</code>. Installs under the selected non-root account’s .local/bin. Authenticate Codex separately from an interactive SSH terminal as the dedicated account; do not paste its login tokens into notes.</p>';
   if($r['recipe']==='firewall')echo '<p>Established connections and the actual local SSH port are preserved. IPv4 and IPv6 inbound traffic is denied except the selected services and essential firewall defaults. Cloud-provider rules are not changed. A two-minute rollback restores the prior inactive firewall unless a fresh pinned SSH check succeeds.</p>';
   self::form($csrf,'system_playbook_apply',$id,$extra);
   if($r['recipe']==='firewall'){echo '<fieldset><legend>Keep these listening services reachable</legend>';foreach($p['inventory']['ports'] as $port){$value=$port['port'].'/'.$port['protocol'];echo '<label class="form-check"><input class="form-check-input" type="checkbox" name="allow_ports[]" value="'.Ui::e($value).'" checked><span class="form-check-label">'.Ui::e($value).'</span></label>';}echo '<p>Uncheck only services you intend to block. The current SSH port remains allowed.</p></fieldset>';}
   if($r['recipe']==='vpn'){echo '<label class="form-label" for="vpn-network">Private VPN subnet (/24)</label><input class="form-control" id="vpn-network" name="vpn_network" value="10.78.0.0/24" required><label class="form-label" for="vpn-port">WireGuard UDP port</label><input class="form-control" id="vpn-port" name="vpn_port" type="number" min="1024" max="65535" value="51820" required><label class="form-label" for="vpn-peer">Client WireGuard public key</label><input class="form-control" id="vpn-peer" name="peer_public_key" maxlength="44" required><label class="form-check"><input type="checkbox" class="form-check-input" name="allow_ufw" value="1" checked><span class="form-check-label">Allow this UDP port in UFW if UFW is already active; preserve all other rules.</span></label><p>Generate the client key on your device. Only its public key belongs here. This creates private access without internet routing. Allow the UDP port in your cloud-provider rules; other firewall frameworks require manual review. A client handshake must be checked separately.</p>';}
   echo '<label class="form-check"><input class="form-check-input" type="checkbox" name="recovery_confirmed" value="1" required><span class="form-check-label">I have tested access to this VPS provider’s recovery console.</span></label><label class="form-label" for="apply-server-action">Type APPLY '.$id.'</label><input class="form-control" id="apply-server-action" name="confirmation" required autocomplete="off"><button class="btn btn-primary">Apply this reviewed action</button></form>';
  }
  if($r['blocking']){echo '<p>The same operation is reconciled after a lost response; installation is never automatically repeated. Stop allows the current package command to finish before pausing.</p>';self::form($csrf,'system_playbook_status',$id,$extra);echo '<button class="btn btn-outline-primary" data-playbook-poll>Check progress</button></form>';self::form($csrf,'system_playbook_stop',$id,$extra);echo '<button class="btn btn-outline-danger">Stop after current command</button></form>';}
  if(isset($p['result'])){echo '<p>'.Ui::e(SystemNotebook::mask((string)($p['result']['summary']??''))).'</p>';if(array_key_exists('reboot_required',$p['result']))echo '<p>'.($p['result']['reboot_required']?'Reboot required; schedule it separately.':'No reboot-required marker was observed.').'</p>';if($r['recipe']==='vpn'&&$r['status']==='complete')echo '<p>Client address: '.Ui::e($p['result']['client_address']??'unknown').'; server public key: <code>'.Ui::e($p['result']['server_public_key']??'unknown').'</code>. Handshake is not yet verified.</p>';}
  if($r['status']!=='review')SystemActionGuide::execution($r,false);
  if($r['status']==='uncertain'){self::form($csrf,'system_playbook_release',$id,$extra);echo '<label class="form-label" for="release-operation">After inspecting the VPS through recovery access, type INSPECTED '.$id.' to release the local operation lock. This does not claim success.</label><input class="form-control" id="release-operation" name="confirmation" required><button class="btn btn-outline-secondary">Record manual inspection and release lock</button></form>';}
  echo '</div></section>';
 }
}
