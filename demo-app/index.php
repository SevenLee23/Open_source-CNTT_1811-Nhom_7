<?php

declare(strict_types=1);
require_once __DIR__ . '/storage.php';
demo_require_login();
$currentAccount = demo_current_user();
if ($currentAccount === null) {
  $_SESSION = [];
  session_destroy();
  header('Location: login.php');
  exit;
}
$initialFeatures = demo_all_features();
$isAdmin = ($currentAccount['role'] ?? '') === 'admin';
$canViewAudit = demo_user_can_view_audit($currentAccount);
$canToggleFlags = $isAdmin ? array_column($initialFeatures, 'key') : ($currentAccount['permissions'] ?? []);
$currentDisplayName = (string) ($currentAccount['display_name'] ?? $currentAccount['username']);
$currentRole = ucfirst((string) ($currentAccount['role'] ?? 'viewer'));
$csrfToken = demo_csrf_token();
$auditCount = $canViewAudit ? count(demo_read_audit()) : 0;
?>
<!doctype html>
<html lang="vi">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#f6f8fb">
  <title>FeatureLab · Quản lý tính năng</title>
  <style>
    :root {
      --ink: #182230;
      --muted: #758195;
      --line: #e8edf3;
      --paper: #fff;
      --canvas: #f6f8fb;
      --nav: #17243a;
      --blue: #4669e8;
      --blue-soft: #edf1ff;
      --green: #138a66;
      --green-soft: #e9f7f1;
      --amber: #a76b13;
      --amber-soft: #fff5df;
      --red: #ce4e4e;
      --shadow: 0 10px 28px rgba(27, 43, 67, .055);
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif
    }

    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      color: var(--ink);
      background: var(--canvas);
      font-size: 14px
    }

    button,
    input,
    select,
    textarea {
      font: inherit
    }

    button {
      cursor: pointer
    }

    .app {
      min-height: 100vh
    }

    .sidebar {
      position: fixed;
      inset: 0 auto 0 0;
      width: 242px;
      background: var(--nav);
      color: #d6deea;
      padding: 24px 15px;
      display: flex;
      flex-direction: column;
      z-index: 5
    }

    .brand {
      display: flex;
      gap: 11px;
      align-items: center;
      padding: 0 11px 26px;
      color: #fff;
      font-weight: 700;
      font-size: 16px;
      letter-spacing: -.3px
    }

    .brand-mark {
      display: grid;
      place-items: center;
      width: 34px;
      height: 34px;
      border-radius: 11px;
      background: #6d88ff;
      color: #fff;
      font-size: 19px
    }

    .workspace {
      margin: 0 7px 24px;
      padding: 12px;
      background: #22324a;
      border: 1px solid #34445a;
      border-radius: 12px;
      display: flex;
      gap: 10px;
      align-items: center
    }

    .workspace-logo {
      width: 32px;
      height: 32px;
      border-radius: 9px;
      display: grid;
      place-items: center;
      background: #d8e7ff;
      color: #3a5dba;
      font-weight: 750
    }

    .workspace small,
    .workspace strong {
      display: block
    }

    .workspace small {
      color: #91a0b6;
      font-size: 10px;
      margin-bottom: 3px
    }

    .workspace strong {
      font-size: 12px;
      color: white
    }

    .workspace .chevron {
      margin-left: auto;
      color: #91a0b6
    }

    .nav-label {
      padding: 0 12px 9px;
      text-transform: uppercase;
      letter-spacing: .11em;
      font-size: 10px;
      color: #8291a7;
      font-weight: 700
    }

    .nav {
      display: grid;
      gap: 4px
    }

    .nav button {
      border: 0;
      text-align: left;
      color: #acb8c9;
      background: transparent;
      padding: 10px 12px;
      border-radius: 9px;
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 13px;
      transition: .15s
    }

    .nav button:hover {
      background: #22324a;
      color: white
    }

    .nav button.active {
      background: #2a3d5a;
      color: white;
      box-shadow: inset 3px 0 #8299ff
    }

    .nav .ico {
      width: 17px;
      text-align: center;
      font-size: 16px;
      opacity: .94
    }

    .nav .count {
      margin-left: auto;
      font-size: 10px;
      background: #344966;
      border-radius: 20px;
      padding: 3px 7px;
      color: #c4d0df
    }

    .side-bottom {
      margin-top: auto
    }

    .env-card {
      margin: 10px 5px 16px;
      background: #202f46;
      border: 1px solid #34445a;
      border-radius: 11px;
      padding: 12px
    }

    .env-card .label {
      color: #9aa9bb;
      font-size: 10px;
      margin-bottom: 9px
    }

    .env-select {
      width: 100%;
      background: #293c57;
      color: white;
      border: 1px solid #435570;
      border-radius: 7px;
      padding: 8px;
      font-size: 12px
    }

    .profile {
      border-top: 1px solid #304057;
      padding: 17px 7px 0;
      display: flex;
      align-items: center;
      gap: 10px
    }

    .avatar {
      width: 32px;
      height: 32px;
      background: #e8c7a9;
      color: #6c4632;
      border-radius: 50%;
      display: grid;
      place-items: center;
      font-size: 11px;
      font-weight: 750
    }

    .profile strong,
    .profile small {
      display: block
    }

    .profile strong {
      color: white;
      font-size: 12px
    }

    .profile small {
      color: #8c9bb0;
      font-size: 10px;
      margin-top: 2px
    }

    .profile .dots {
      margin-left: auto;
      color: #9ba9bc
    }

    .main {
      margin-left: 242px;
      min-height: 100vh
    }

    .topbar {
      height: 66px;
      padding: 0 36px;
      background: rgba(255, 255, 255, .8);
      border-bottom: 1px solid var(--line);
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 4;
      backdrop-filter: blur(14px)
    }

    .crumb {
      color: #8290a3;
      font-size: 12px
    }

    .crumb b {
      color: #344153;
      font-weight: 600
    }

    .top-actions {
      display: flex;
      align-items: center;
      gap: 12px
    }

    .icon-btn {
      width: 34px;
      height: 34px;
      border: 1px solid var(--line);
      border-radius: 9px;
      background: white;
      color: #667489;
      display: grid;
      place-items: center;
      position: relative
    }

    .notify-dot {
      position: absolute;
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: #ee7272;
      right: 7px;
      top: 7px
    }

    .help {
      font-size: 12px;
      color: #718096
    }

    .content {
      max-width: 1390px;
      padding: 29px 36px 50px;
      margin: auto
    }

    .page-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 20px;
      margin-bottom: 24px
    }

    .eyebrow {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .1em;
      color: #8491a4;
      font-weight: 700;
      margin-bottom: 8px
    }

    .page-head h1 {
      font-size: 25px;
      letter-spacing: -.7px;
      margin: 0 0 7px;
      font-weight: 700
    }

    .page-head p {
      color: var(--muted);
      margin: 0;
      font-size: 13px
    }

    .head-actions {
      display: flex;
      gap: 9px;
      align-items: center;
      padding-top: 6px
    }

    .btn {
      border: 1px solid #dce3ec;
      background: #fff;
      color: #344153;
      border-radius: 8px;
      padding: 9px 13px;
      font-weight: 600;
      font-size: 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      transition: .15s
    }

    .btn:hover {
      border-color: #aebbd0;
      transform: translateY(-1px)
    }

    .btn.primary {
      background: var(--blue);
      border-color: var(--blue);
      color: #fff;
      box-shadow: 0 3px 8px #4669e833
    }

    .btn.primary:hover {
      background: #385ad4
    }

    .btn.ghost {
      background: transparent
    }

    .btn.small {
      padding: 6px 9px;
      font-size: 11px
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 14px;
      margin-bottom: 18px
    }

    .card {
      background: var(--paper);
      border: 1px solid var(--line);
      border-radius: 12px;
      box-shadow: var(--shadow)
    }

    .stat {
      padding: 17px 18px 15px
    }

    .stat-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
      color: #778397;
      font-size: 11px;
      font-weight: 600
    }

    .stat-ico {
      width: 31px;
      height: 31px;
      border-radius: 9px;
      background: #f0f3ff;
      color: #627ce5;
      display: grid;
      place-items: center;
      font-size: 15px
    }

    .stat-value {
      font-size: 27px;
      font-weight: 720;
      letter-spacing: -1px;
      margin: 12px 0 8px
    }

    .stat-foot {
      font-size: 10px;
      color: #8591a1
    }

    .trend {
      color: var(--green);
      font-weight: 700
    }

    .dashboard-grid {
      display: grid;
      grid-template-columns: minmax(0, 1.6fr) minmax(270px, .85fr);
      gap: 15px;
      margin-bottom: 16px
    }

    .card-head {
      padding: 17px 19px 13px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid #f0f2f6
    }

    .card-head h2 {
      font-size: 13px;
      margin: 0;
      font-weight: 700
    }

    .card-head p {
      font-size: 10px;
      color: #8a95a5;
      margin: 5px 0 0
    }

    .card-body {
      padding: 17px 19px
    }

    .release-line {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 17px
    }

    .release-line strong {
      font-size: 12px
    }

    .release-line span {
      font-size: 10px;
      color: #8995a5
    }

    .chart {
      height: 152px;
      position: relative;
      padding: 4px 0 22px 31px
    }

    .chart-grid {
      position: absolute;
      inset: 7px 0 24px 31px;
      display: flex;
      flex-direction: column;
      justify-content: space-between
    }

    .chart-grid i {
      border-top: 1px dashed #e8edf3;
      width: 100%
    }

    .chart svg {
      position: absolute;
      inset: 5px 0 25px 29px;
      width: calc(100% - 32px);
      height: 122px;
      overflow: visible
    }

    .chart-labels {
      position: absolute;
      left: 0;
      top: 0;
      height: 124px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      color: #9aa5b3;
      font-size: 9px
    }

    .chart-x {
      position: absolute;
      bottom: 0;
      left: 31px;
      right: 0;
      display: flex;
      justify-content: space-between;
      color: #9aa5b3;
      font-size: 9px
    }

    .legend {
      display: flex;
      gap: 16px;
      color: #8490a0;
      font-size: 10px;
      margin-top: 3px
    }

    .legend i {
      display: inline-block;
      width: 7px;
      height: 7px;
      background: #6681ed;
      border-radius: 50%;
      margin-right: 5px
    }

    .legend span:last-child i {
      background: #46bd96
    }

    .health-list {
      display: grid;
      gap: 0
    }

    .health-row {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 12px 0;
      border-bottom: 1px solid #f0f2f6
    }

    .health-row:last-child {
      border-bottom: 0
    }

    .health-icon {
      width: 30px;
      height: 30px;
      border-radius: 9px;
      background: #f0f4ff;
      color: #617be6;
      display: grid;
      place-items: center;
      font-size: 13px
    }

    .health-meta {
      flex: 1
    }

    .health-meta b {
      font-size: 11px;
      display: block
    }

    .health-meta small {
      font-size: 10px;
      color: #8793a2;
      display: block;
      margin-top: 3px
    }

    .health-status {
      font-size: 10px;
      color: var(--green);
      font-weight: 700
    }

    .health-status:before {
      content: '';
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: #41b78c;
      display: inline-block;
      margin-right: 5px
    }

    .bottom-grid {
      display: grid;
      grid-template-columns: minmax(0, 1.25fr) minmax(300px, 1fr);
      gap: 15px
    }

    .table-wrap {
      overflow: auto
    }

    table {
      width: 100%;
      border-collapse: collapse;
      white-space: nowrap
    }

    th {
      text-align: left;
      background: #fbfcfe;
      font-size: 9px;
      text-transform: uppercase;
      letter-spacing: .07em;
      color: #8994a3;
      font-weight: 700;
      padding: 10px 16px;
      border-bottom: 1px solid #edf0f4
    }

    td {
      padding: 12px 16px;
      border-bottom: 1px solid #f0f2f5;
      font-size: 11px;
      color: #4c586b
    }

    tbody tr:last-child td {
      border-bottom: 0
    }

    .flag-name {
      display: flex;
      align-items: center;
      gap: 9px
    }

    .flag-dot {
      width: 28px;
      height: 28px;
      border-radius: 8px;
      background: #eff2ff;
      color: #637ee9;
      font-weight: 700;
      display: grid;
      place-items: center;
      font-size: 10px
    }

    .flag-name b {
      display: block;
      color: #293447;
      font-size: 11px
    }

    .flag-name small {
      display: block;
      color: #919bab;
      font-family: ui-monospace, monospace;
      font-size: 9px;
      margin-top: 3px
    }

    .badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 8px;
      border-radius: 20px;
      font-size: 9px;
      font-weight: 700
    }

    .badge.on {
      background: var(--green-soft);
      color: var(--green)
    }

    .badge.off {
      background: #f0f2f5;
      color: #7e8898
    }

    .badge.draft {
      background: var(--amber-soft);
      color: var(--amber)
    }

    .badge.live {
      background: #edf1ff;
      color: #536fdb
    }

    .badge:before {
      content: '';
      width: 5px;
      height: 5px;
      border-radius: 50%;
      background: currentColor
    }

    .rollout {
      display: flex;
      align-items: center;
      gap: 8px
    }

    .progress {
      width: 65px;
      height: 5px;
      border-radius: 8px;
      background: #edf0f4;
      overflow: hidden
    }

    .progress i {
      height: 100%;
      display: block;
      background: #7288e8;
      border-radius: 8px
    }

    .version-list {
      display: grid
    }

    .version-item {
      display: grid;
      grid-template-columns: 28px 1fr auto;
      gap: 10px;
      padding: 12px 0;
      border-bottom: 1px solid #f0f2f5;
      align-items: start
    }

    .version-item:last-child {
      border-bottom: 0
    }

    .version-icon {
      width: 27px;
      height: 27px;
      border-radius: 8px;
      background: #eef7f3;
      color: #2c9a76;
      display: grid;
      place-items: center;
      font-size: 12px
    }

    .version-copy b {
      display: block;
      font-size: 11px
    }

    .version-copy small {
      display: block;
      font-size: 10px;
      color: #8994a2;
      margin-top: 4px
    }

    .version-time {
      font-size: 9px;
      color: #939eac;
      white-space: nowrap
    }

    .activity {
      display: grid
    }

    .activity-item {
      display: grid;
      grid-template-columns: 25px 1fr auto;
      gap: 9px;
      padding: 10px 0;
      border-bottom: 1px solid #f1f3f6;
      align-items: start
    }

    .activity-item:last-child {
      border-bottom: 0
    }

    .mini-avatar {
      width: 24px;
      height: 24px;
      border-radius: 50%;
      background: #f4e4d7;
      color: #895f40;
      display: grid;
      place-items: center;
      font-size: 8px;
      font-weight: 700
    }

    .activity-item:nth-child(2) .mini-avatar {
      background: #e5eaff;
      color: #596fc7
    }

    .activity-item:nth-child(3) .mini-avatar {
      background: #e3f4ed;
      color: #33876b
    }

    .activity-copy {
      font-size: 10px;
      color: #606c7e;
      line-height: 1.5
    }

    .activity-copy b {
      color: #2d394b
    }

    .activity-copy code {
      font-size: 9px;
      color: #536fdb;
      background: #f2f4ff;
      padding: 2px 4px;
      border-radius: 4px
    }

    .activity-item time {
      font-size: 9px;
      color: #9aa4b1;
      white-space: nowrap
    }

    .toolbar {
      display: flex;
      align-items: center;
      gap: 9px;
      margin-bottom: 14px
    }

    .search {
      position: relative;
      flex: 1;
      max-width: 340px
    }

    .search input {
      width: 100%;
      padding: 9px 12px 9px 33px;
      border: 1px solid #e0e6ee;
      border-radius: 8px;
      background: white;
      font-size: 11px;
      outline: none
    }

    .search input:focus {
      border-color: #91a4f0;
      box-shadow: 0 0 0 3px #4669e814
    }

    .search:before {
      content: '⌕';
      position: absolute;
      left: 12px;
      top: 5px;
      font-size: 17px;
      color: #97a2b1
    }

    .select {
      border: 1px solid #e0e6ee;
      border-radius: 8px;
      background: white;
      padding: 9px 28px 9px 10px;
      color: #526073;
      font-size: 11px
    }

    .push {
      margin-left: auto
    }

    .pill {
      border: 1px solid #e2e7ee;
      border-radius: 8px;
      padding: 8px 10px;
      color: #647186;
      background: #fff;
      font-size: 10px
    }

    .toggle {
      width: 32px;
      height: 18px;
      border: 0;
      border-radius: 12px;
      background: #d6dce5;
      padding: 2px;
      display: inline-flex;
      align-items: center;
      transition: .2s
    }

    .toggle i {
      width: 14px;
      height: 14px;
      background: white;
      border-radius: 50%;
      box-shadow: 0 1px 2px #0002;
      transition: .2s
    }

    .toggle.on {
      background: #45b58f
    }

    .toggle.on i {
      transform: translateX(14px)
    }

    .table-card {
      overflow: hidden
    }

    .table-card td {
      padding-top: 13px;
      padding-bottom: 13px
    }

    .table-card th {
      padding-top: 12px;
      padding-bottom: 12px
    }

    .table-footer {
      padding: 12px 16px;
      border-top: 1px solid #edf0f4;
      display: flex;
      justify-content: space-between;
      color: #8994a3;
      font-size: 10px
    }

    .pagination {
      display: flex;
      gap: 5px
    }

    .pagination button {
      border: 1px solid #e6eaf0;
      background: white;
      border-radius: 6px;
      width: 25px;
      height: 24px;
      color: #738095;
      font-size: 10px
    }

    .pagination button.sel {
      color: white;
      background: var(--blue);
      border-color: var(--blue)
    }

    .hidden {
      display: none !important
    }

    .versions-layout {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 15px
    }

    .version-card {
      padding: 0 18px
    }

    .timeline-row {
      display: grid;
      grid-template-columns: 30px 1fr auto;
      gap: 11px;
      padding: 16px 0;
      border-bottom: 1px solid #eff2f5;
      align-items: start
    }

    .timeline-dot {
      height: 29px;
      width: 29px;
      border-radius: 9px;
      background: #edf1ff;
      color: #5a74df;
      display: grid;
      place-items: center;
      font-size: 11px
    }

    .timeline-row:last-child {
      border-bottom: 0
    }

    .timeline-row b {
      font-size: 11px
    }

    .timeline-row p {
      margin: 4px 0;
      font-size: 10px;
      color: #7f8b9a
    }

    .timeline-row small {
      font-size: 9px;
      color: #9aa4b2
    }

    .compare-box {
      padding: 16px
    }

    .compare-selects {
      display: flex;
      align-items: center;
      gap: 8px;
      margin: 12px 0 17px
    }

    .compare-selects select {
      min-width: 0;
      flex: 1
    }

    .diff-row {
      padding: 11px 0;
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 8px;
      border-top: 1px solid #eff2f5;
      font-size: 10px
    }

    .diff-row span:first-child {
      color: #657286
    }

    .diff-row del {
      color: #cb5555;
      text-decoration: none
    }

    .diff-row ins {
      color: #198a65;
      text-decoration: none
    }

    .playground {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(300px, .9fr);
      gap: 15px
    }

    .form-card {
      padding: 20px
    }

    .field {
      margin-bottom: 15px
    }

    .field label {
      display: block;
      color: #526073;
      font-size: 10px;
      font-weight: 700;
      margin-bottom: 7px
    }

    .field input,
    .field select,
    .field textarea {
      width: 100%;
      border: 1px solid #dfe5ed;
      border-radius: 8px;
      padding: 10px 11px;
      outline: none;
      font-size: 11px;
      color: #384457;
      background: white
    }

    .field input:focus,
    .field select:focus {
      border-color: #93a5f0;
      box-shadow: 0 0 0 3px #4669e812
    }

    .field small {
      display: block;
      font-size: 9px;
      color: #8d98a7;
      margin-top: 5px
    }

    .result-card {
      padding: 20px;
      position: relative;
      overflow: hidden
    }

    .result-empty {
      min-height: 270px;
      display: grid;
      place-items: center;
      text-align: center;
      color: #8793a2
    }

    .result-empty .spark {
      margin: auto auto 12px;
      width: 44px;
      height: 44px;
      border-radius: 14px;
      background: #eff2ff;
      color: #637ce4;
      display: grid;
      place-items: center;
      font-size: 20px
    }

    .result-on {
      border-radius: 10px;
      background: #edf8f3;
      padding: 15px;
      color: #187c5d
    }

    .result-on h3 {
      margin: 0 0 5px;
      font-size: 16px
    }

    .result-on p {
      font-size: 10px;
      color: #538e7a;
      margin: 0
    }

    .result-off {
      border-radius: 10px;
      background: #fff0f0;
      padding: 15px;
      color: #b34848
    }

    .result-off h3 {
      margin: 0 0 5px;
      font-size: 16px
    }

    .result-off p {
      font-size: 10px;
      color: #a56a6a;
      margin: 0
    }

    .reason-list {
      margin-top: 15px
    }

    .reason-row {
      display: flex;
      justify-content: space-between;
      padding: 9px 0;
      border-bottom: 1px solid #f0f2f5;
      font-size: 10px
    }

    .reason-row span:first-child {
      color: #8994a2
    }

    .reason-row b {
      font-weight: 650;
      color: #39465a
    }

    .settings-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 15px
    }

    .settings-card {
      padding: 19px
    }

    .settings-card h2 {
      font-size: 13px;
      margin: 0 0 6px
    }

    .settings-card>p {
      font-size: 10px;
      color: #8792a2;
      margin: 0 0 17px
    }

    .dropzone {
      border: 1px dashed #cbd4e0;
      border-radius: 10px;
      padding: 27px;
      text-align: center;
      color: #7d8998;
      background: #fbfcfe
    }

    .dropzone strong {
      display: block;
      color: #455267;
      font-size: 11px;
      margin: 7px 0
    }

    .dropzone small {
      font-size: 9px
    }

    .dropzone input {
      display: none
    }

    .json-area {
      width: 100%;
      min-height: 180px;
      resize: vertical;
      font: 10px/1.6 ui-monospace, monospace;
      background: #f9fafc;
      border: 1px solid #e5e9ef;
      border-radius: 8px;
      padding: 12px;
      color: #526176
    }

    .toast {
      position: fixed;
      right: 22px;
      bottom: 22px;
      background: #20334c;
      color: white;
      border-radius: 9px;
      padding: 12px 16px;
      font-size: 12px;
      box-shadow: 0 10px 30px #14243c44;
      z-index: 10;
      opacity: 0;
      transform: translateY(10px);
      transition: .2s;
      pointer-events: none
    }

    .toast.show {
      opacity: 1;
      transform: translateY(0)
    }

    .menu-btn {
      display: none
    }

    .mobile-overlay {
      display: none
    }

    @media(max-width:1050px) {
      .sidebar {
        width: 218px
      }

      .main {
        margin-left: 218px
      }

      .content {
        padding: 25px 23px 40px
      }

      .topbar {
        padding: 0 23px
      }

      .stats {
        grid-template-columns: repeat(2, 1fr)
      }

      .dashboard-grid,
      .bottom-grid {
        grid-template-columns: 1fr
      }

      .health-list {
        grid-template-columns: 1fr 1fr;
        column-gap: 20px
      }

      .playground {
        grid-template-columns: 1fr 1fr
      }
    }

    @media(max-width:700px) {
      .sidebar {
        transform: translateX(-100%);
        transition: transform .22s;
        width: 250px
      }

      .sidebar.open {
        transform: translateX(0)
      }

      .mobile-overlay.show {
        display: block;
        position: fixed;
        inset: 0;
        background: #0e172866;
        z-index: 4
      }

      .main {
        margin-left: 0
      }

      .topbar {
        height: 58px;
        padding: 0 16px
      }

      .menu-btn {
        display: grid
      }

      .crumb {
        font-size: 11px
      }

      .help {
        display: none
      }

      .content {
        padding: 22px 15px 35px
      }

      .page-head {
        display: block
      }

      .page-head h1 {
        font-size: 22px
      }

      .head-actions {
        padding-top: 16px
      }

      .head-actions .btn {
        flex: 1
      }

      .stats {
        gap: 9px
      }

      .stat {
        padding: 13px
      }

      .stat-value {
        font-size: 23px
      }

      .stat-top {
        font-size: 10px
      }

      .stat-foot {
        font-size: 9px
      }

      .dashboard-grid,
      .bottom-grid,
      .versions-layout,
      .playground,
      .settings-grid {
        grid-template-columns: 1fr
      }

      .health-list {
        grid-template-columns: 1fr
      }

      .toolbar {
        flex-wrap: wrap
      }

      .search {
        max-width: none;
        flex-basis: 100%
      }

      .push {
        margin-left: 0
      }

      .table-card {
        overflow: auto
      }

      .top-actions {
        gap: 7px
      }
    }

    .account-menu {
      position: relative;
      border-top: 1px solid #304057;
      margin-top: 15px;
      padding-top: 13px
    }

    .profile {
      width: 100%;
      text-align: left;
      background: transparent;
      border: 0;
      cursor: pointer;
      border-radius: 9px;
      padding: 7px 5px;
      transition: background .15s
    }

    .profile:hover,
    .profile[aria-expanded="true"] {
      background: #22324a
    }

    .profile .account-chevron {
      margin-left: auto;
      color: #9ba9bc;
      font-size: 12px;
      transition: transform .15s
    }

    .profile[aria-expanded="true"] .account-chevron {
      transform: rotate(180deg)
    }

    .account-dropdown {
      display: none;
      position: absolute;
      z-index: 8;
      left: 0;
      right: 0;
      bottom: 54px;
      padding: 6px;
      background: #fff;
      border: 1px solid #e8edf3;
      border-radius: 11px;
      box-shadow: 0 14px 32px #0b183444
    }

    .account-menu.open .account-dropdown {
      display: block
    }

    .account-dropdown button {
      width: 100%;
      display: flex;
      align-items: center;
      gap: 10px;
      border: 0;
      border-radius: 7px;
      background: transparent;
      padding: 10px;
      color: #465367;
      text-align: left;
      font-size: 11px;
      cursor: pointer
    }

    .account-dropdown button:hover {
      background: #f3f5fa;
      color: #26364d
    }

    .account-dropdown .menu-icon {
      width: 16px;
      text-align: center;
      color: #8290a3;
      font-size: 14px
    }

    .account-dropdown .menu-separator {
      height: 1px;
      background: #edf0f4;
      margin: 5px 4px
    }

    .account-dropdown .logout-item {
      color: #c34f4f
    }

    .account-dropdown .logout-item .menu-icon {
      color: #c34f4f
    }

    .top-account-menu {
      border: 0;
      margin: 0;
      padding: 0
    }

    .top-account-menu .profile {
      width: auto;
      padding: 3px;
      border: 1px solid transparent;
      border-radius: 50%;
      background: transparent
    }

    .top-account-menu .profile:hover,
    .top-account-menu .profile[aria-expanded="true"] {
      background: #edf1ff;
      border-color: #e2e8ff
    }

    .top-account-menu .profile .avatar {
      width: 29px;
      height: 29px
    }

    .top-account-menu .profile .account-chevron {
      display: none
    }

    .top-account-menu .account-dropdown {
      top: 42px;
      bottom: auto;
      left: auto;
      right: 0;
      width: 205px
    }

    .top-account-menu .account-dropdown form {
      margin: 0
    }

    .top-account-menu .account-dropdown button {
      font-size: 12px
    }

    .toggle:disabled {
      opacity: .42;
      cursor: not-allowed
    }

    .permission-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin: 15px 0 8px
    }

    .permission-head label {
      font-size: 10px;
      font-weight: 700;
      color: #526073
    }

    .permission-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 6px;
      max-height: 205px;
      overflow: auto;
      padding: 2px
    }

    .permission-grid.disabled {
      opacity: .45;
      pointer-events: none
    }

    .permission-option {
      display: flex;
      align-items: flex-start;
      gap: 7px;
      padding: 8px;
      border: 1px solid #e9edf3;
      border-radius: 8px;
      cursor: pointer
    }

    .permission-option input {
      margin: 2px 0 0;
      accent-color: #4669e8
    }

    .permission-option b,
    .permission-option small {
      display: block
    }

    .permission-option b {
      font-size: 9px;
      color: #3b485b
    }

    .permission-option small {
      font: 8px ui-monospace, monospace;
      color: #8994a3;
      margin-top: 3px
    }

    .form-message {
      min-height: 15px;
      margin-top: 7px;
      font-size: 10px;
      color: #657286
    }

    .form-message.error {
      color: #c34f4f
    }

    .account-admin-grid {
      grid-template-columns: minmax(310px, .9fr) minmax(0, 1.4fr)
    }

    .user-permissions {
      white-space: normal;
      min-width: 120px;
      max-width: 250px;
      color: #728095;
      font-size: 9px;
      line-height: 1.6
    }

    .role-pill {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 20px;
      background: #edf1ff;
      color: #536fdb;
      font-size: 9px;
      font-weight: 700
    }

    .role-pill.viewer {
      background: #f0f2f5;
      color: #788394
    }
  </style>
  <style>
    @media(max-width:900px) {
      .account-admin-grid {
        grid-template-columns: 1fr
      }
    }

    .audit-access-option {
      display: flex;
      align-items: flex-start;
      gap: 9px;
      border: 1px solid #e9edf3;
      border-radius: 8px;
      padding: 10px;
      margin: 6px 0 13px;
      cursor: pointer
    }

    .audit-access-option input {
      margin: 2px 0 0;
      accent-color: #4669e8
    }

    .audit-access-option b,
    .audit-access-option small {
      display: block
    }

    .audit-access-option b {
      font-size: 10px;
      color: #3b485b
    }

    .audit-access-option small {
      font-size: 9px;
      color: #8994a3;
      margin-top: 4px
    }
  </style>
</head>

<body>
  <div class="app">
    <aside class="sidebar" id="sidebar">
      <div class="brand"><span class="brand-mark">✳</span> FeatureLab</div>
      <div class="workspace"><span class="workspace-logo">F</span><span><small>WORKSPACE</small><strong>FeatureLab Team</strong></span><span class="chevron">⌄</span></div>
      <div class="nav-label">Workspace</div>
      <nav class="nav" id="nav">
        <button class="active" data-page="dashboard"><span class="ico">▦</span>Dashboard</button>
        <button data-page="features"><span class="ico">◈</span>Features<span class="count">10</span></button>
        <button data-page="versions"><span class="ico">◷</span>Version History</button>
        <button data-page="playground"><span class="ico">⌘</span>Evaluation Playground</button>
        <button data-page="activity"><span class="ico">☷</span>Audit Log &amp; Import/Export</button>
        <?php if ($isAdmin): ?><button data-page="accounts"><span class="ico">♧</span>Tài khoản &amp; phân quyền</button><?php endif; ?>
      </nav>
      <div class="side-bottom">
        <div class="env-card">
          <div class="label">MÔI TRƯỜNG ĐANG XEM</div><select id="envSelect" class="env-select">
            <option value="staging">◉ &nbsp; Staging</option>
            <option value="production_sim">◉ &nbsp; Production Sim</option>
          </select>
        </div>
        <div class="account-menu" id="accountMenu">
          <button class="profile" id="profileTrigger" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="accountDropdown"><span class="avatar"><?= htmlspecialchars(strtoupper(substr($currentDisplayName, 0, 2)), ENT_QUOTES, 'UTF-8') ?></span><span><strong><?= htmlspecialchars($currentDisplayName, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8') ?></small></span><span class="account-chevron">⌃</span></button>
          <div class="account-dropdown" id="accountDropdown" role="menu" aria-label="Tùy chọn tài khoản">
            <button type="button" role="menuitem" data-account-action="profile"><span class="menu-icon">◎</span>Thông tin cá nhân</button>
            <button type="button" role="menuitem" data-account-action="settings"><span class="menu-icon">⚙</span>Cài đặt</button>
            <div class="menu-separator"></div>
            <form action="logout.php" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(demo_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><button class="logout-item" type="submit" role="menuitem"><span class="menu-icon">↗</span>Đăng xuất</button></form>
          </div>
        </div>
      </div>
    </aside>
    <div class="mobile-overlay" id="overlay"></div>
    <main class="main">
      <header class="topbar">
        <div style="display:flex;align-items:center;gap:12px"><button class="icon-btn menu-btn" id="menuBtn" aria-label="Mở menu">☰</button>
          <div class="crumb">Projects&nbsp; / &nbsp;<b>FeatureLab</b></div>
        </div>
        <div class="top-actions"><span class="help">Trợ giúp</span><button class="icon-btn" aria-label="Thông báo">♧<i class="notify-dot"></i></button>
          <div class="account-menu top-account-menu" id="topAccountMenu"><button class="profile" id="topProfileTrigger" type="button" aria-label="Mở menu tài khoản" aria-haspopup="true" aria-expanded="false" aria-controls="topAccountDropdown"><span class="avatar"><?= htmlspecialchars(strtoupper(substr($currentDisplayName, 0, 2)), ENT_QUOTES, 'UTF-8') ?></span></button>
            <div class="account-dropdown" id="topAccountDropdown" role="menu" aria-label="Tùy chọn tài khoản"><button type="button" role="menuitem" data-account-action="profile"><span class="menu-icon">◎</span>Thông tin cá nhân</button><button type="button" role="menuitem" data-account-action="settings"><span class="menu-icon">⚙</span>Cài đặt</button>
              <div class="menu-separator"></div>
              <form action="logout.php" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><button class="logout-item" type="submit" role="menuitem"><span class="menu-icon">↗</span>Đăng xuất</button></form>
            </div>
          </div>
        </div>
      </header>
      <div class="content">
        <section class="page" id="page-dashboard">
          <div class="page-head">
            <div>
              <div class="eyebrow">TỔNG QUAN DỰ ÁN</div>
              <h1>Xin chào, <?= htmlspecialchars($currentDisplayName, ENT_QUOTES, 'UTF-8') ?> 👋</h1>
              <p>Theo dõi trạng thái tính năng và hoạt động triển khai của bạn.</p>
            </div>
            <div class="head-actions"><button class="btn" data-go="versions">◷ &nbsp; Version history</button><button class="btn primary" data-go="features">＋ &nbsp; Tạo feature flag</button></div>
          </div>
          <div class="stats">
            <article class="card stat">
              <div class="stat-top">Tổng số tính năng <span class="stat-ico">◈</span></div>
              <div class="stat-value" id="statTotal">10</div>
              <div class="stat-foot"><span class="trend">↗ 2 mới</span> trong tháng này</div>
            </article>
            <article class="card stat">
              <div class="stat-top">Đang hoạt động <span class="stat-ico" style="background:#eaf7f1;color:#35a17c">◉</span></div>
              <div class="stat-value" id="statActive">7</div>
              <div class="stat-foot"><span class="trend">70%</span> tính năng đang bật</div>
            </article>
            <article class="card stat">
              <div class="stat-top">Phiên bản hiện tại <span class="stat-ico" style="background:#fff5e5;color:#c18a37">◷</span></div>
              <div class="stat-value" style="font-size:24px">v1.8.0</div>
              <div class="stat-foot">Phát hành bởi <b style="color:#4b596b">Alex Lee</b> · 2 giờ trước</div>
            </article>
            <article class="card stat">
              <div class="stat-top">Lượt đánh giá hôm nay <span class="stat-ico" style="background:#f3edff;color:#8965c9">⌘</span></div>
              <div class="stat-value">24.8k</div>
              <div class="stat-foot"><span class="trend">↗ 12.4%</span> so với hôm qua</div>
            </article>
          </div>
          <div class="dashboard-grid">
            <article class="card">
              <div class="card-head">
                <div>
                  <h2>Evaluation activity</h2>
                  <p>Lượt đánh giá tính năng trong 7 ngày gần nhất</p>
                </div><select class="select">
                  <option>7 ngày qua</option>
                  <option>30 ngày qua</option>
                </select>
              </div>
              <div class="card-body">
                <div class="release-line"><strong>186,420 <span style="color:#8591a0;font-weight:400">lượt đánh giá</span></strong><span class="trend">↗ 8.2%</span></div>
                <div class="chart">
                  <div class="chart-labels"><span>40k</span><span>30k</span><span>20k</span><span>10k</span><span>0</span></div>
                  <div class="chart-grid"><i></i><i></i><i></i><i></i><i></i></div><svg viewBox="0 0 600 125" preserveAspectRatio="none" aria-label="Biểu đồ lượt đánh giá">
                    <defs>
                      <linearGradient id="fillBlue" x1="0" x2="0" y1="0" y2="1">
                        <stop offset="0" stop-color="#6983ee" stop-opacity=".19" />
                        <stop offset="1" stop-color="#6983ee" stop-opacity="0" />
                      </linearGradient>
                    </defs>
                    <path d="M0 91 C34 86 40 61 82 71 S130 91 164 60 S213 71 245 51 S289 72 326 44 S370 62 409 38 S455 55 490 28 S544 42 600 12 L600 125 L0 125Z" fill="url(#fillBlue)" />
                    <path d="M0 91 C34 86 40 61 82 71 S130 91 164 60 S213 71 245 51 S289 72 326 44 S370 62 409 38 S455 55 490 28 S544 42 600 12" fill="none" stroke="#6681ed" stroke-width="2.5" />
                    <path d="M0 108 C34 111 55 88 82 98 S132 101 164 92 S210 103 245 84 S290 94 326 88 S374 98 409 77 S458 89 490 69 S548 78 600 58" fill="none" stroke="#48bb92" stroke-width="2" stroke-dasharray="5 4" />
                  </svg>
                  <div class="chart-x"><span>18/10</span><span>19/10</span><span>20/10</span><span>21/10</span><span>22/10</span><span>23/10</span><span>24/10</span></div>
                </div>
                <div class="legend"><span><i></i>Evaluations</span><span><i></i>Unique users</span></div>
              </div>
            </article>
            <article class="card">
              <div class="card-head">
                <div>
                  <h2>Trạng thái hệ thống</h2>
                  <p>Các thành phần đang vận hành ổn định</p>
                </div><span class="badge on">All systems operational</span>
              </div>
              <div class="card-body health-list">
                <div class="health-row"><span class="health-icon">⌘</span><span class="health-meta"><b>Evaluation API</b><small>p95 latency · 42 ms</small></span><span class="health-status">Healthy</span></div>
                <div class="health-row"><span class="health-icon">▤</span><span class="health-meta"><b>MySQL Database</b><small>Connection pool · 18%</small></span><span class="health-status">Healthy</span></div>
                <div class="health-row"><span class="health-icon">◷</span><span class="health-meta"><b>Configuration cache</b><small>Last sync · 1 min ago</small></span><span class="health-status">Synced</span></div>
                <div class="health-row"><span class="health-icon">⌁</span><span class="health-meta"><b>Audit logging</b><small>Queue · 0 pending</small></span><span class="health-status">Healthy</span></div>
              </div>
            </article>
          </div>
          <div class="bottom-grid">
            <article class="card">
              <div class="card-head">
                <div>
                  <h2>Features gần đây</h2>
                  <p>Các tính năng vừa được cập nhật trong dự án</p>
                </div><button class="btn small" data-go="features">Xem tất cả →</button>
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>FEATURE</th>
                      <th>TRẠNG THÁI</th>
                      <th>ROLLOUT</th>
                      <th>CẬP NHẬT</th>
                    </tr>
                  </thead>
                  <tbody id="recentRows"></tbody>
                </table>
              </div>
            </article><?php if ($canViewAudit): ?><article class="card">
                <div class="card-head">
                  <div>
                    <h2>Hoạt động gần đây</h2>
                    <p>Các thay đổi mới nhất trong workspace</p>
                  </div><button class="btn small" data-go="activity">Xem nhật ký →</button>
                </div>
                <div class="card-body activity" id="recentAudit"></div>
              </article><?php endif; ?>
          </div>
        </section>
        <section class="page hidden" id="page-features">
          <div class="page-head">
            <div>
              <div class="eyebrow">CẤU HÌNH DỰ ÁN</div>
              <h1>Feature flags</h1>
              <p>Tạo, quản lý và kiểm soát rollout cho các tính năng của bạn.</p>
            </div>
            <div class="head-actions"><button class="btn" id="exportBtn">↓ &nbsp; Export JSON</button><button class="btn primary" id="newFeatureBtn">＋ &nbsp; Tạo feature</button></div>
          </div>
          <div class="stats">
            <article class="card stat">
              <div class="stat-top">Tất cả features <span class="stat-ico">◈</span></div>
              <div class="stat-value" id="featureTotal">10</div>
              <div class="stat-foot">Trong dự án FeatureLab</div>
            </article>
            <article class="card stat">
              <div class="stat-top">Đang bật <span class="stat-ico" style="background:#eaf7f1;color:#35a17c">◉</span></div>
              <div class="stat-value" id="featureOn">7</div>
              <div class="stat-foot">Trên môi trường đang chọn</div>
            </article>
            <article class="card stat">
              <div class="stat-top">Bản nháp <span class="stat-ico" style="background:#fff5e5;color:#c18a37">◷</span></div>
              <div class="stat-value">2</div>
              <div class="stat-foot">Chưa phát hành</div>
            </article>
            <article class="card stat">
              <div class="stat-top">Target groups <span class="stat-ico" style="background:#f3edff;color:#8965c9">♧</span></div>
              <div class="stat-value">3</div>
              <div class="stat-foot">internal · beta · general</div>
            </article>
          </div>
          <div class="toolbar"><label class="search"><input id="featureSearch" placeholder="Tìm theo tên hoặc key..."></label><select class="select" id="statusFilter">
              <option value="all">Tất cả trạng thái</option>
              <option value="on">Đang bật</option>
              <option value="off">Đang tắt</option>
            </select><select class="select">
              <option>Tất cả nhóm</option>
              <option>internal_testers</option>
              <option>beta_users</option>
              <option>general_users</option>
            </select><span class="push" style="font-size:10px;color:#8490a0">Môi trường: <b id="envLabel" style="color:#49566a">Staging</b></span></div>
          <article class="card table-card">
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>FEATURE</th>
                    <th>TRẠNG THÁI</th>
                    <th>ROLLOUT</th>
                    <th>NHÓM ÁP DỤNG</th>
                    <th>GIÁ TRỊ DỰ PHÒNG</th>
                    <th>CẬP NHẬT</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody id="featureRows"></tbody>
              </table>
            </div>
            <div class="table-footer"><span id="featureCountLabel">Hiển thị 10 trên 10 features</span>
              <div class="pagination"><button>‹</button><button class="sel">1</button><button>›</button></div>
            </div>
          </article>
        </section>
        <section class="page hidden" id="page-versions">
          <div class="page-head">
            <div>
              <div class="eyebrow">PHÁT HÀNH AN TOÀN</div>
              <h1>Version history</h1>
              <p>Theo dõi thay đổi cấu hình, so sánh và khôi phục phiên bản.</p>
            </div>
            <div class="head-actions"><button class="btn" id="compareBtn">⇄ &nbsp; So sánh phiên bản</button><button class="btn primary" id="publishBtn">↑ &nbsp; Phát hành bản nháp</button></div>
          </div>
          <div class="stats">
            <article class="card stat">
              <div class="stat-top">Phiên bản hiện tại <span class="stat-ico">◷</span></div>
              <div class="stat-value" style="font-size:23px">v1.8.0</div>
              <div class="stat-foot"><span class="badge on">Đang phát hành</span></div>
            </article>
            <article class="card stat">
              <div class="stat-top">Tổng phiên bản <span class="stat-ico">▤</span></div>
              <div class="stat-value">18</div>
              <div class="stat-foot">Từ khi khởi tạo dự án</div>
            </article>
            <article class="card stat">
              <div class="stat-top">Bản nháp <span class="stat-ico" style="background:#fff5e5;color:#bd8734">◷</span></div>
              <div class="stat-value">2</div>
              <div class="stat-foot">Sẵn sàng kiểm tra</div>
            </article>
            <article class="card stat">
              <div class="stat-top">Môi trường <span class="stat-ico" style="background:#eaf7f1;color:#35a17c">◉</span></div>
              <div class="stat-value">2</div>
              <div class="stat-foot">Staging · Production Sim</div>
            </article>
          </div>
          <div class="versions-layout">
            <article class="card">
              <div class="card-head">
                <div>
                  <h2>Lịch sử phát hành</h2>
                  <p>Phiên bản cấu hình theo thời gian</p>
                </div><select class="select">
                  <option>Gần đây nhất</option>
                  <option>Cũ nhất</option>
                </select>
              </div>
              <div class="version-card" id="timeline"></div>
            </article>
            <article class="card">
              <div class="card-head">
                <div>
                  <h2>So sánh cấu hình</h2>
                  <p>Xem nhanh khác biệt giữa hai phiên bản</p>
                </div><span class="badge live">DIFF VIEW</span>
              </div>
              <div class="compare-box">
                <div class="compare-selects"><select class="select">
                    <option>v1.8.0 · Current</option>
                    <option>v1.7.2</option>
                  </select><span style="color:#8b97a7">→</span><select class="select">
                    <option>v1.7.2 · Previous</option>
                    <option>v1.6.0</option>
                  </select></div>
                <div class="diff-row" style="border-top:0;font-weight:700;color:#8591a0;font-size:9px"><span>FEATURE</span><span>TRƯỚC</span><span>HIỆN TẠI</span></div>
                <div class="diff-row"><span>beta_search rollout</span><del>10%</del><ins>25%</ins></div>
                <div class="diff-row"><span>dark_mode default</span><del>false</del><ins>true</ins></div>
                <div class="diff-row"><span>new_dashboard group</span><del>—</del><ins>early_access</ins></div>
                <div class="field" style="margin:17px 0 0"><label>LÝ DO PHÁT HÀNH</label><input value="Mở rộng thử nghiệm beta search" readonly></div><button class="btn" style="width:100%;margin-top:8px" id="rollbackBtn">↶ &nbsp; Tạo bản rollback từ v1.7.2</button><small style="display:block;text-align:center;color:#919bab;font-size:9px;margin-top:8px">Rollback tạo phiên bản mới, không thay đổi lịch sử cũ.</small>
              </div>
            </article>
          </div>
        </section>
        <section class="page hidden" id="page-playground">
          <div class="page-head">
            <div>
              <div class="eyebrow">KIỂM TRA QUY TẮC</div>
              <h1>Evaluation playground</h1>
              <p>Mô phỏng kết quả đánh giá feature flag theo người dùng và môi trường.</p>
            </div>
            <div class="head-actions"><span class="badge live">● &nbsp; Snapshot v1.8.0</span></div>
          </div>
          <div class="playground">
            <article class="card form-card">
              <div class="card-head" style="padding:0 0 15px;margin-bottom:17px">
                <div>
                  <h2>Thông tin đánh giá</h2>
                  <p>Nhập context để kiểm tra logic bật/tắt</p>
                </div><span class="stat-ico">⌘</span>
              </div>
              <div class="field"><label>FEATURE FLAG</label><select id="evalFlag"></select></div>
              <div class="field"><label>MÃ NGƯỜI DÙNG</label><input id="evalUser" value="USER_0001" placeholder="Ví dụ: USER_0001"><small>Rollout dùng bucket hash ổn định cho cùng người dùng.</small></div>
              <div class="field"><label>MÔI TRƯỜNG</label><select id="evalEnv">
                  <option value="staging">Staging</option>
                  <option value="production_sim">Production Sim</option>
                </select></div><button class="btn primary" id="evaluateBtn" style="width:100%">▶ &nbsp; Đánh giá feature</button>
              <div style="font-size:9px;color:#97a1ae;text-align:center;margin-top:10px">Thứ tự: Kill switch → User → Group → Rollout → Default</div>
            </article>
            <article class="card result-card">
              <div class="card-head" style="padding:0 0 15px;margin-bottom:17px">
                <div>
                  <h2>Kết quả đánh giá</h2>
                  <p id="resultSubtitle">Kết quả sẽ hiển thị ở đây</p>
                </div><span class="badge live">API SIMULATION</span>
              </div>
              <div id="evalResult" class="result-empty">
                <div>
                  <div class="spark">✦</div><strong>Chưa có kết quả</strong>
                  <div style="font-size:10px;margin-top:5px">Nhập thông tin và chạy đánh giá để xem chi tiết.</div>
                </div>
              </div>
            </article>
          </div>
        </section>
        <section class="page hidden" id="page-activity">
          <div class="page-head">
            <div>
              <div class="eyebrow">QUẢN TRỊ &amp; KIỂM SOÁT</div>
              <h1><?= $canViewAudit ? 'Audit log &amp; Import/Export' : 'Import/Export' ?></h1>
              <p><?= $canViewAudit ? 'Nhật ký thao tác và trao đổi dữ liệu JSON.' : 'Trao đổi cấu hình JSON trong phạm vi quyền được cấp.' ?></p>
            </div>
            <div class="head-actions"><button class="btn" id="downloadJson">↓ &nbsp; Tải JSON</button><button class="btn primary" data-go="features">＋ &nbsp; Quản lý features</button></div>
          </div>
          <div class="stats">
            <article class="card stat">
              <div class="stat-top"><?= $canViewAudit ? 'Sự kiện được ghi nhận' : 'Quyền nhật ký' ?> <span class="stat-ico">☷</span></div>
              <div class="stat-value"><?= $canViewAudit ? $auditCount : '—' ?></div>
              <div class="stat-foot"><?= $canViewAudit ? 'Số sự kiện trong file log' : 'Admin quản lý quyền xem' ?></div>
            </article>
            <article class="card stat">
              <div class="stat-top">Lượt đánh giá <span class="stat-ico" style="background:#f3edff;color:#8965c9">⌘</span></div>
              <div class="stat-value">24.8k</div>
              <div class="stat-foot">Decision log hôm nay</div>
            </article>
            <article class="card stat">
              <div class="stat-top">Lần import gần nhất <span class="stat-ico" style="background:#eaf7f1;color:#35a17c">↓</span></div>
              <div class="stat-value" style="font-size:22px">20/10</div>
              <div class="stat-foot">feature-flags.json</div>
            </article>
            <article class="card stat">
              <div class="stat-top">Thành viên dự án <span class="stat-ico" style="background:#fff5e5;color:#bd8734">♧</span></div>
              <div class="stat-value">4</div>
              <div class="stat-foot">1 Admin · 2 Editor · 1 Viewer</div>
            </article>
          </div>
          <div class="toolbar"><label class="search"><input id="auditSearch" placeholder="Tìm người dùng, hành động..."></label><select class="select">
              <option>Tất cả hoạt động</option>
              <option>Phát hành</option>
              <option>Cập nhật feature</option>
              <option>Import / Export</option>
            </select><select class="select">
              <option>7 ngày qua</option>
              <option>30 ngày qua</option>
            </select></div>
          <?php if ($canViewAudit): ?><article class="card table-card" style="margin-bottom:16px">
              <div class="card-head">
                <div>
                  <h2>Audit log</h2>
                  <p>Nhật ký chỉ đọc, được máy chủ ghi nhận khi có thay đổi.</p>
                </div><span class="badge on">● APPEND ONLY</span>
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>NGƯỜI THỰC HIỆN</th>
                      <th>HÀNH ĐỘNG</th>
                      <th>ĐỐI TƯỢNG</th>
                      <th>THAY ĐỔI</th>
                      <th>THỜI GIAN</th>
                    </tr>
                  </thead>
                  <tbody id="auditRows"></tbody>
                </table>
              </div>
              <div class="table-footer"><span id="auditCount">Đang tải nhật ký...</span><span>Nhật ký không thể sửa trong ứng dụng</span></div>
            </article><?php endif; ?>
          <div class="settings-grid">
            <article class="card settings-card">
              <h2>Import cấu hình</h2>
              <p>Tải JSON, kiểm tra hợp lệ rồi nhập vào bản nháp.</p><label class="dropzone" for="jsonFile"><span style="font-size:22px;color:#7287e7">⇧</span><strong>Kéo thả file JSON vào đây</strong><small>hoặc nhấn để chọn · tối đa 2 MB</small><input type="file" id="jsonFile" accept=".json,application/json"></label>
              <div id="importMessage" style="font-size:10px;color:#758195;margin-top:9px"></div><button class="btn" id="importBtn" style="width:100%;margin-top:12px">Kiểm tra &amp; import JSON</button>
            </article>
            <article class="card settings-card">
              <h2>Export cấu hình</h2>
              <p>Xuất cấu hình hiện tại, không bao gồm API key hoặc dữ liệu bí mật.</p><textarea class="json-area" id="jsonPreview" readonly></textarea><button class="btn" id="copyJson" style="width:100%;margin-top:10px">▣ &nbsp; Sao chép JSON</button>
            </article>
          </div>
        </section>
        <?php if ($isAdmin): ?>
          <section class="page hidden" id="page-accounts">
            <div class="page-head">
              <div>
                <div class="eyebrow">QUẢN TRỊ THÀNH VIÊN</div>
                <h1>Tài khoản &amp; phân quyền</h1>
                <p>Tạo tài khoản và chọn chính xác feature mà từng Editor được phép thay đổi.</p>
              </div>
              <div class="head-actions"><span class="badge live">ADMIN ACCESS</span></div>
            </div>
            <div class="settings-grid account-admin-grid">
              <article class="card settings-card">
                <h2>Tạo tài khoản mới</h2>
                <p>Viewer chỉ xem. Editor chỉ bật/tắt các feature được cấp quyền.</p>
                <form id="createAccountForm">
                  <div class="field"><label for="newDisplayName">TÊN HIỂN THỊ</label><input id="newDisplayName" name="display_name" placeholder="Ví dụ: Nguyen An"></div>
                  <div class="field"><label for="newUsername">TÊN ĐĂNG NHẬP</label><input id="newUsername" name="username" required minlength="3" maxlength="32" pattern="[A-Za-z0-9._-]+" placeholder="nguyen.an"></div>
                  <div class="field"><label for="newPassword">MẬT KHẨU TẠM THỜI</label><input id="newPassword" name="password" type="password" required minlength="8" autocomplete="new-password" placeholder="Ít nhất 8 ký tự"></div>
                  <div class="field"><label for="newRole">VAI TRÒ</label><select id="newRole" name="role">
                      <option value="editor">Editor · chỉnh sửa feature được cấp</option>
                      <option value="viewer">Viewer · chỉ xem</option>
                    </select></div>
                  <label class="audit-access-option"><input id="grantAuditAccess" type="checkbox"><span><b>Cho phép xem Audit Log</b><small>Quyền chỉ đọc nhật ký thao tác của dự án.</small></span></label>
                  <div class="permission-head"><label>FEATURE ĐƯỢC PHÉP BẬT / TẮT</label><button type="button" class="btn small" id="selectAllPermissions">Chọn tất cả</button></div>
                  <div class="permission-grid" id="permissionGrid"><?php foreach ($initialFeatures as $feature): ?><label class="permission-option"><input type="checkbox" name="permissions[]" value="<?= htmlspecialchars((string) $feature['key'], ENT_QUOTES, 'UTF-8') ?>"><span><b><?= htmlspecialchars((string) $feature['name'], ENT_QUOTES, 'UTF-8') ?></b><small><?= htmlspecialchars((string) $feature['key'], ENT_QUOTES, 'UTF-8') ?></small></span></label><?php endforeach; ?></div>
                  <div class="form-message" id="createAccountMessage" role="status"></div>
                  <button class="btn primary" type="submit" style="width:100%;margin-top:13px">＋ &nbsp; Tạo tài khoản</button>
                </form>
              </article>
              <article class="card table-card">
                <div class="card-head">
                  <div>
                    <h2>Tài khoản trong dự án</h2>
                    <p>Quyền được kiểm tra ở máy chủ mỗi khi truy cập nhật ký hoặc thay đổi feature.</p>
                  </div><span class="badge on">SERVER AUTHORIZATION</span>
                </div>
                <div class="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th>TÀI KHOẢN</th>
                        <th>VAI TRÒ</th>
                        <th>QUYỀN FEATURE</th>
                        <th>XEM AUDIT LOG</th>
                        <th>NGÀY TẠO</th>
                      </tr>
                    </thead>
                    <tbody id="usersRows">
                      <tr>
                        <td colspan="5" style="padding:22px;text-align:center;color:#8793a2">Đang tải tài khoản...</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </article>
            </div>
          </section>
        <?php endif; ?>
      </div>
    </main>
  </div>
  <div class="toast" id="toast"></div>
  <script>
    const features = <?= json_encode($initialFeatures, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
    const canViewAudit = <?= $canViewAudit ? 'true' : 'false' ?>;
    const canToggleFlags = <?= json_encode(array_values($canToggleFlags), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const versions = [
      ['v1.8.0', 'Current release', 'Alex Lee', '2 giờ trước', 'Mở rộng thử nghiệm beta search', 'live'],
      ['v1.7.2', 'Published', 'Minh K.', 'Hôm qua', 'Bật dark mode cho general users', 'live'],
      ['v1.7.1', 'Published', 'Jamie Tran', '20/10/2026', 'Hotfix: tắt quick filters', 'live'],
      ['v1.7.0', 'Published', 'Alex Lee', '18/10/2026', 'Rollout team workspaces lên 50%', 'live'],
      ['v1.6.0', 'Published', 'Minh K.', '15/10/2026', 'Thêm beta search vào beta users', 'live'],
      ['Draft #19', 'Draft', 'Alex Lee', 'Đang chỉnh sửa', 'Thử nghiệm AI suggestions', 'draft']
    ];
    let auditEvents = [];
    let auditLoaded = false;
    const $ = s => document.querySelector(s);
    const esc = s => String(s).replace(/[&<>"']/g, c => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    } [c]));

    function notify(msg) {
      const t = $('#toast');
      t.textContent = msg;
      t.classList.add('show');
      clearTimeout(window.toastTimer);
      window.toastTimer = setTimeout(() => t.classList.remove('show'), 2600)
    }

    function envName() {
      return $('#envSelect').value === 'staging' ? 'Staging' : 'Production Sim'
    }

    function featureTableRow(f) {
      const canToggle = isAdmin || canToggleFlags.includes(f.key);
      return `<tr><td><div class="flag-name"><span class="flag-dot">${esc(f.key.slice(0,2).toUpperCase())}</span><span><b>${esc(f.name)}</b><small>${esc(f.key)}</small></span></div></td><td><span class="badge ${f.on?'on':'off'}">${f.on?'Enabled':'Disabled'}</span></td><td><div class="rollout"><span>${f.rollout}%</span><span class="progress"><i style="width:${f.rollout}%"></i></span></div></td><td><span style="font-family:ui-monospace,monospace;font-size:10px">${esc(f.group)}</span></td><td><span class="badge ${f.fallback==='true'?'on':'off'}">${esc(f.fallback)}</span></td><td>${esc(f.updated)}</td><td><button class="toggle ${f.on?'on':''}" data-toggle="${esc(f.key)}" aria-label="Bật tắt ${esc(f.key)}" ${canToggle?'':'disabled title="Bạn không được cấp quyền thay đổi feature này"'}><i></i></button></td></tr>`
    }

    function renderFeatures() {
      const q = ($('#featureSearch')?.value || '').toLowerCase(),
        status = $('#statusFilter')?.value || 'all';
      const rows = features.filter(f => (f.key + ' ' + f.name).toLowerCase().includes(q) && (status === 'all' || (status === 'on') === f.on));
      $('#featureRows').innerHTML = rows.map(featureTableRow).join('') || '<tr><td colspan="7" style="text-align:center;padding:25px;color:#8793a2">Không tìm thấy feature phù hợp.</td></tr>';
      $('#featureTotal').textContent = features.length;
      $('#featureOn').textContent = features.filter(f => f.on).length;
      $('#featureCountLabel').textContent = `Hiển thị ${rows.length} trên ${features.length} features`;
      $('#statTotal').textContent = features.length;
      $('#statActive').textContent = features.filter(f => f.on).length;
      $('#recentRows').innerHTML = features.slice(0, 4).map(f => `<tr><td><div class="flag-name"><span class="flag-dot">${f.key.slice(0,2).toUpperCase()}</span><span><b>${esc(f.name)}</b><small>${esc(f.key)}</small></span></div></td><td><span class="badge ${f.on?'on':'off'}">${f.on?'Enabled':'Disabled'}</span></td><td>${f.rollout}%</td><td>${esc(f.updated)}</td></tr>`).join('');
      $('#evalFlag').innerHTML = features.map(f => `<option value="${esc(f.key)}">${esc(f.name)} · ${esc(f.key)}</option>`).join('');
    }

    function renderTimeline() {
      $('#timeline').innerHTML = versions.map((v, i) => `<div class="timeline-row"><span class="timeline-dot">${i===0?'✓':'◷'}</span><div><b>${v[0]} <span class="badge ${v[5]}" style="margin-left:5px">${v[1]}</span></b><p>${esc(v[4])}</p><small>Phát hành bởi ${esc(v[2])} · ${esc(v[3])}</small></div><button class="btn small" data-rollback="${v[0]}">⋯</button></div>`).join('')
    }

    function auditActionLabel(action) {
      return ({
        'feature.enabled': 'Bật feature',
        'feature.disabled': 'Tắt feature',
        'account.created': 'Tạo tài khoản',
        'audit.access_granted': 'Cấp quyền xem log',
        'audit.access_revoked': 'Thu hồi quyền xem log'
      })[action] || action
    }
    async function ensureAuditEvents() {
      if (!canViewAudit) return [];
      if (!auditLoaded) {
        const result = await apiRequest('audit');
        auditEvents = result.events || [];
        auditLoaded = true
      }
      return auditEvents
    }

    function auditRow(event) {
      const actor = event.actor_name || event.actor_username || 'Tài khoản';
      const target = event.feature_key || event.target_username || '—';
      let change = event.reason || '—';
      if (event.action === 'feature.enabled' || event.action === 'feature.disabled') change = `${event.previous_value?'Bật':'Tắt'} → ${event.new_value?'Bật':'Tắt'}`;
      return `<tr><td><div class="flag-name"><span class="mini-avatar">${esc(actor.slice(0,2).toUpperCase())}</span><span><b>${esc(actor)}</b><small>${esc(event.actor_username||'')} · ${esc(event.actor_role||'')}</small></span></div></td><td>${esc(auditActionLabel(event.action||''))}</td><td><code style="font-size:10px;color:#526fd9">${esc(target)}</code></td><td>${esc(change)}</td><td>${esc(event.occurred_at?new Date(event.occurred_at).toLocaleString('vi-VN'):'—')}</td></tr>`
    }
    async function renderAudit(q = '') {
      const body = $('#auditRows');
      if (!body || !canViewAudit) return;
      body.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:22px;color:#8793a2">Đang tải nhật ký...</td></tr>';
      try {
        const events = await ensureAuditEvents(),
          filtered = events.filter(event => JSON.stringify(event).toLowerCase().includes(q.toLowerCase()));
        body.innerHTML = filtered.map(auditRow).join('') || '<tr><td colspan="5" style="text-align:center;padding:22px;color:#8793a2">Chưa có hoạt động được ghi nhận.</td></tr>';
        $('#auditCount').textContent = `${filtered.length} sự kiện · ${events.length} tổng cộng`
      } catch (error) {
        body.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:22px;color:#c34f4f">${esc(error.message)}</td></tr>`
      }
    }
    async function renderRecentAudit() {
      const body = $('#recentAudit');
      if (!body || !canViewAudit) return;
      try {
        const events = (await ensureAuditEvents()).slice(0, 3);
        body.innerHTML = events.map(event => `<div class="activity-item"><span class="mini-avatar">${esc((event.actor_name||event.actor_username||'A').slice(0,2).toUpperCase())}</span><span class="activity-copy"><b>${esc(event.actor_name||event.actor_username)}</b> ${esc(auditActionLabel(event.action||''))} <code>${esc(event.feature_key||event.target_username||'')}</code></span><time>${esc(event.occurred_at?new Date(event.occurred_at).toLocaleDateString('vi-VN'):'')}</time></div>`).join('') || '<div style="font-size:10px;color:#8793a2;padding:10px 0">Chưa có thay đổi.</div>'
      } catch {
        body.innerHTML = '<div style="font-size:10px;color:#8793a2;padding:10px 0">Không tải được hoạt động.</div>'
      }
    }

    function go(page) {
      document.querySelectorAll('.page').forEach(p => p.classList.add('hidden'));
      const target = $('#page-' + page);
      if (!target) return;
      target.classList.remove('hidden');
      document.querySelectorAll('#nav button').forEach(b => b.classList.toggle('active', b.dataset.page === page));
      $('#sidebar').classList.remove('open');
      $('#overlay').classList.remove('show');
      if (page === 'accounts' && isAdmin) loadUsers();
      if (page === 'activity' && canViewAudit) {
        auditLoaded = false;
        renderAudit($('#auditSearch')?.value || '')
      }
      window.scrollTo({
        top: 0,
        behavior: 'smooth'
      })
    }

    function exportData() {
      return {
        project: {
          name: 'FeatureLab',
          key: 'featurelab'
        },
        environment: $('#envSelect').value,
        version: '1.8.0',
        flags: features.map(({
          key,
          name,
          on,
          rollout,
          group,
          fallback
        }) => ({
          key,
          name,
          enabled: on,
          rollout_percentage: rollout,
          target_group: group,
          fallback_value: fallback === 'true'
        }))
      }
    }

    function download() {
      const blob = new Blob([JSON.stringify(exportData(), null, 2)], {
          type: 'application/json'
        }),
        a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'feature-flags.json';
      a.click();
      URL.revokeObjectURL(a.href);
      notify('Đã xuất cấu hình feature-flags.json')
    }
    async function apiRequest(action, data = null) {
      const options = data ? {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          ...data,
          csrf_token: csrfToken
        })
      } : {};
      const response = await fetch(`api.php?action=${encodeURIComponent(action)}`, options);
      let result;
      try {
        result = await response.json()
      } catch {
        throw new Error('Máy chủ trả về dữ liệu không hợp lệ.')
      }
      if (!response.ok || !result.ok) throw new Error(result.message || 'Yêu cầu không thành công.');
      return result
    }
    async function persistFeatureToggle(feature, button) {
      const desired = !feature.on;
      button.disabled = true;
      try {
        const result = await apiRequest('toggle_feature', {
          key: feature.key,
          enabled: desired
        });
        features.splice(0, features.length, ...result.features);
        renderFeatures();
        auditLoaded = false;
        notify(`${feature.key} đã ${desired?'bật':'tắt'} và được lưu`);
        if (canViewAudit) {
          renderRecentAudit();
          renderAudit($('#auditSearch')?.value || '')
        }
      } catch (error) {
        notify(error.message)
      } finally {
        const refreshed = Array.from(document.querySelectorAll('[data-toggle]')).find(item => item.dataset.toggle === feature.key);
        if (refreshed) refreshed.disabled = !(isAdmin || canToggleFlags.includes(feature.key))
      }
    }
    async function loadUsers() {
      const body = $('#usersRows');
      if (!body) return;
      body.innerHTML = '<tr><td colspan="5" style="padding:22px;text-align:center;color:#8793a2">Đang tải tài khoản...</td></tr>';
      try {
        const result = await apiRequest('users');
        body.innerHTML = result.users.map(account => {
          const permissions = account.role === 'admin' ? 'Toàn quyền' : account.role === 'viewer' ? 'Chỉ xem' : (account.permissions || []).map(key => {
            const flag = features.find(item => item.key === key);
            return flag ? flag.name : key
          }).join(', ') || 'Chưa được cấp quyền';
          const date = account.created_at ? new Date(account.created_at).toLocaleDateString('vi-VN') : '—';
          const auditAccess = account.role === 'admin' ? '<span class="role-pill">Luôn có</span>' : `<button class="toggle ${account.can_view_audit?'on':''}" data-audit-toggle="${esc(account.username)}" data-enabled="${account.can_view_audit?'true':'false'}" aria-label="Đổi quyền xem nhật ký cho ${esc(account.username)}"><i></i></button>`;
          return `<tr><td><div class="flag-name"><span class="mini-avatar">${esc((account.display_name||account.username).slice(0,2).toUpperCase())}</span><span><b>${esc(account.display_name||account.username)}</b><small>${esc(account.username)}</small></span></div></td><td><span class="role-pill ${account.role==='viewer'?'viewer':''}">${esc(account.role.toUpperCase())}</span></td><td><div class="user-permissions">${esc(permissions)}</div></td><td>${auditAccess}</td><td>${esc(date)}</td></tr>`
        }).join('') || '<tr><td colspan="5" style="padding:22px;text-align:center;color:#8793a2">Chưa có tài khoản.</td></tr>'
      } catch (error) {
        body.innerHTML = `<tr><td colspan="5" style="padding:22px;text-align:center;color:#c34f4f">${esc(error.message)}</td></tr>`
      }
    }
    async function setAuditAccess(username, enabled, button) {
      button.disabled = true;
      try {
        const result = await apiRequest('set_audit_access', {
          username,
          can_view_audit: enabled
        });
        auditLoaded = false;
        await loadUsers();
        notify(result.message);
        renderAudit($('#auditSearch')?.value || '')
      } catch (error) {
        notify(error.message)
      } finally {
        button.disabled = false
      }
    }

    function stableBucket(user, key, env) {
      let h = 2166136261;
      for (const c of `1:${env}:${key}:${user}`) {
        h ^= c.charCodeAt(0);
        h = Math.imul(h, 16777619)
      }
      return (h >>> 0) % 10000
    }

    function evaluate() {
      const f = features.find(x => x.key === $('#evalFlag').value),
        user = $('#evalUser').value.trim() || 'anonymous',
        env = $('#evalEnv').value;
      let enabled = false,
        reason = 'default_value',
        detail = 'Dùng giá trị mặc định của phiên bản';
      if (f.kill) {
        reason = 'kill_switch';
        detail = 'Tính năng đã bị tắt khẩn cấp';
      } else if (user === 'USER_0001' && f.key === 'new_dashboard') {
        enabled = true;
        reason = 'user_rule';
        detail = 'Quy tắc người dùng ưu tiên';
      } else if (f.on) {
        const bucket = stableBucket(user, f.key, env);
        enabled = f.rollout >= 100 || (f.rollout > 0 && bucket < f.rollout * 100);
        reason = 'percentage_rollout';
        detail = `Bucket ổn định ${bucket}/10000 · rollout ${f.rollout}%`;
      } else {
        enabled = f.def;
        reason = 'default_value';
        detail = 'Feature đang tắt; sử dụng default value'
      }
      const box = $('#evalResult');
      box.className = '';
      box.innerHTML = `<div class="result-${enabled?'on':'off'}"><h3>${enabled?'✓ Feature is enabled':'⊘ Feature is disabled'}</h3><p>${esc(f.name)} · ${esc(user)}</p></div><div class="reason-list"><div class="reason-row"><span>Decision</span><b>${enabled?'true':'false'}</b></div><div class="reason-row"><span>Reason</span><b>${esc(reason)}</b></div><div class="reason-row"><span>Rule detail</span><b>${esc(detail)}</b></div><div class="reason-row"><span>Version</span><b>v1.8.0 · ${esc(env)}</b></div><div class="reason-row"><span>Fallback value</span><b>${esc(f.fallback)}</b></div></div>`;
      $('#resultSubtitle').textContent = `Đánh giá ${f.key} cho ${user}`
    }
    $('#nav').addEventListener('click', e => {
      const b = e.target.closest('[data-page]');
      if (b) go(b.dataset.page)
    });
    document.addEventListener('click', e => {
      const g = e.target.closest('[data-go]');
      if (g) go(g.dataset.go);
      const t = e.target.closest('[data-toggle]');
      if (t && !t.disabled) {
        const f = features.find(x => x.key === t.dataset.toggle);
        if (f) persistFeatureToggle(f, t)
      }
      const auditToggle = e.target.closest('[data-audit-toggle]');
      if (auditToggle && !auditToggle.disabled) setAuditAccess(auditToggle.dataset.auditToggle, auditToggle.dataset.enabled !== 'true', auditToggle);
      const rb = e.target.closest('[data-rollback]');
      if (rb) notify(`Tạo bản nháp rollback từ ${rb.dataset.rollback}`)
    });
    $('#featureSearch').addEventListener('input', renderFeatures);
    $('#statusFilter').addEventListener('change', renderFeatures);
    $('#newFeatureBtn').addEventListener('click', () => {
      const key = prompt('Nhập key cho feature mới (ví dụ: saved_views):');
      if (!key) return;
      const normalized = key.trim().toLowerCase().replace(/[^a-z0-9_]+/g, '_');
      if (!normalized || features.some(f => f.key === normalized)) {
        notify('Key không hợp lệ hoặc đã tồn tại');
        return
      }
      features.unshift({
        key: normalized,
        name: normalized.split('_').map(x => x[0]?.toUpperCase() + x.slice(1)).join(' '),
        on: false,
        rollout: 0,
        group: 'internal_testers',
        fallback: 'false',
        updated: 'Vừa xong',
        kill: false,
        def: false
      });
      renderFeatures();
      notify('Feature mới đã được thêm vào bản nháp')
    });
    $('#evaluateBtn').addEventListener('click', evaluate);
    $('#evalUser').addEventListener('keydown', e => {
      if (e.key === 'Enter') evaluate()
    });
    $('#envSelect').addEventListener('change', () => {
      $('#envLabel').textContent = envName();
      $('#evalEnv').value = $('#envSelect').value;
      notify(`Đang xem môi trường ${envName()}`)
    });
    $('#evalEnv').addEventListener('change', () => {
      $('#envSelect').value = $('#evalEnv').value;
      $('#envLabel').textContent = envName()
    });
    $('#publishBtn').addEventListener('click', () => notify('Đã kiểm tra cấu hình · sẵn sàng phát hành'));
    $('#rollbackBtn').addEventListener('click', () => notify('Đã tạo bản nháp rollback từ v1.7.2'));
    $('#compareBtn').addEventListener('click', () => notify('Đang so sánh v1.8.0 và v1.7.2'));
    $('#exportBtn').addEventListener('click', download);
    $('#downloadJson').addEventListener('click', download);
    $('#jsonPreview').value = JSON.stringify(exportData(), null, 2);
    $('#copyJson').addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(JSON.stringify(exportData(), null, 2));
        notify('Đã sao chép JSON')
      } catch {
        notify('Hãy chọn và sao chép JSON thủ công')
      }
    });
    $('#auditSearch').addEventListener('input', e => renderAudit(e.target.value));
    $('#jsonFile').addEventListener('change', e => {
      const f = e.target.files[0];
      if (!f) return;
      if (f.size > 2 * 1024 * 1024) {
        $('#importMessage').textContent = 'File vượt quá giới hạn 2 MB.';
        return
      }
      const r = new FileReader();
      r.onload = () => {
        try {
          const data = JSON.parse(r.result);
          $('#jsonPreview').value = JSON.stringify(data, null, 2);
          $('#importMessage').innerHTML = '<span style="color:#138a66">✓ JSON hợp lệ · sẵn sàng nhập vào bản nháp.</span>';
          $('#jsonPreview').dataset.import = 'true'
        } catch {
          $('#importMessage').innerHTML = '<span style="color:#ce4e4e">JSON không hợp lệ. Kiểm tra lại cú pháp file.</span>';
          $('#jsonPreview').dataset.import = 'false'
        }
      };
      r.readAsText(f)
    });
    $('#importBtn').addEventListener('click', () => notify($('#jsonPreview').dataset.import === 'true' ? 'Import hợp lệ · cấu hình đã sẵn sàng trong bản nháp' : 'Chọn file JSON hợp lệ trước khi import'));
    $('#menuBtn').addEventListener('click', () => {
      $('#sidebar').classList.add('open');
      $('#overlay').classList.add('show')
    });
    $('#overlay').addEventListener('click', () => {
      $('#sidebar').classList.remove('open');
      $('#overlay').classList.remove('show')
    });
    renderFeatures();
    renderTimeline();
    if (canViewAudit) {
      renderAudit();
      renderRecentAudit()
    }
    document.querySelectorAll('.account-menu').forEach(menu => {
      const trigger = menu.querySelector('.profile');
      trigger.addEventListener('click', () => {
        const open = menu.classList.toggle('open');
        trigger.setAttribute('aria-expanded', String(open));
        document.querySelectorAll('.account-menu').forEach(other => {
          if (other !== menu) {
            other.classList.remove('open');
            other.querySelector('.profile').setAttribute('aria-expanded', 'false')
          }
        })
      })
    });
    document.addEventListener('click', e => {
      document.querySelectorAll('.account-menu').forEach(menu => {
        if (!menu.contains(e.target)) {
          menu.classList.remove('open');
          menu.querySelector('.profile').setAttribute('aria-expanded', 'false')
        }
      });
      const action = e.target.closest('[data-account-action]');
      if (action) {
        const username = <?= json_encode((string) ($_SESSION['demo_user'] ?? 'Admin'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        notify(action.dataset.accountAction === 'settings' ? 'Cài đặt workspace' : 'Thông tin cá nhân của ' + username)
      }
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') document.querySelectorAll('.account-menu').forEach(menu => {
        menu.classList.remove('open');
        menu.querySelector('.profile').setAttribute('aria-expanded', 'false')
      })
    });
    if (isAdmin) {
      const roleSelect = $('#newRole'),
        permissionGrid = $('#permissionGrid'),
        createForm = $('#createAccountForm');
      roleSelect.addEventListener('change', () => {
        const viewer = roleSelect.value === 'viewer';
        permissionGrid.classList.toggle('disabled', viewer);
        permissionGrid.querySelectorAll('input').forEach(input => {
          input.disabled = viewer;
          if (viewer) input.checked = false
        })
      });
      $('#selectAllPermissions').addEventListener('click', () => permissionGrid.querySelectorAll('input:not(:disabled)').forEach(input => input.checked = true));
      createForm.addEventListener('submit', async event => {
        event.preventDefault();
        const message = $('#createAccountMessage'),
          submit = createForm.querySelector('button[type="submit"]');
        message.classList.remove('error');
        message.textContent = '';
        submit.disabled = true;
        try {
          const permissions = Array.from(permissionGrid.querySelectorAll('input:checked')).map(input => input.value);
          const result = await apiRequest('create_user', {
            username: $('#newUsername').value.trim(),
            display_name: $('#newDisplayName').value.trim(),
            password: $('#newPassword').value,
            role: roleSelect.value,
            permissions,
            can_view_audit: $('#grantAuditAccess').checked
          });
          message.textContent = result.message;
          createForm.reset();
          permissionGrid.classList.remove('disabled');
          permissionGrid.querySelectorAll('input').forEach(input => input.disabled = false);
          auditLoaded = false;
          await loadUsers();
          notify(result.message);
          if (canViewAudit) renderRecentAudit()
        } catch (error) {
          message.textContent = error.message;
          message.classList.add('error')
        } finally {
          submit.disabled = false
        }
      });
    }
  </script>
</body>

</html>