<?php
/**
 * AIcontrol — private cPanel project control and credential broker.
 * Generated loader.php is the only file uploaded to the public site.
 * PHP 8.2+, cURL, Sodium, OpenSSL, session, JSON and ZipArchive.
 */
declare(strict_types=1);
const AC_VERSION = '0.1.0';
const AC_REPO = 'efrgdgr0024345/AIcontrol';
const AC_REPOSITORY_ID = '1450838309';
const AC_OWNER_ID = '263261716';
const AC_MAX_ARCHIVE = 33554432;
const AC_MAX_EXPANDED = 67108864;
const AC_MAX_FILE = 8388608;
const AC_REQUIRED_CHECKS = ['package-contract', 'security-tests (8.2)', 'security-tests (8.5)'];
if (!defined('AC_PRIVATE_ROOT')) {
    define('AC_PRIVATE_ROOT', getenv('AICONTROL_PRIVATE_ROOT') ?: '/home/learning/.aicontrol');
}
/* AC_PROJECT_JSON_BEGIN
{
  "schema": 1,
  "title": "AIcontrol",
  "version": "0.1.0",
  "phase": "First release — installation and live provider checks required",
  "summary": "A private project console, server-side credential vault, scoped agent requests and reviewed updates.",
  "scope": ["Keep long-lived credentials on cPanel.", "Execute generated project code in isolated cloud jobs.", "Keep the human in control of sensitive actions."],
  "decisions": [{"title":"One-file installation", "text":"Reuse the owner's loader workflow with private persistent state and an exact-commit update gate."}],
  "milestones": [{"id":"AC-001", "title":"Vault, access requests, history and update foundation", "status":"in_review"}, {"id":"AC-002", "title":"Install and verify on cPanel", "status":"not_started"}, {"id":"AC-003", "title":"Full project build/test orchestration", "status":"not_started"}],
  "references": [{"name":"Owner's existing loader", "work":"LearnPiano and DeterministicFace update, backup and recovery code.", "url":""}],
  "history": [{"date":"2026-11-09", "text":"AIcontrol established with server-side credentials and explicitly approved agent operations."}]
}
AC_PROJECT_JSON_END */
