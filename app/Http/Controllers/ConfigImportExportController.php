<?php
namespace App\Http\Controllers;

use App\Http\Requests\ImportConfigRequest;
use App\Http\Requests\ImportLogsRequest;
use App\Models\Environment;
use App\Models\Version;
use App\Services\ImportExportService;
use App\Support\Auth;
use App\Support\Database;

final class ConfigImportExportController extends Controller
{
    public function export()
    {
        $this->user();
        $projectId = $this->projectId();
        if (!Auth::canViewAudit($projectId)) { http_response_code(403); exit('Admin chưa cấp quyền xem cấu hình xuất.'); }
        $environmentId = Auth::environmentId($projectId);
        $environment = Environment::find($environmentId);
        $isTestEnvironment = $this->isTestEnvironment($projectId, $environmentId);
        $version = ($isTestEnvironment ? Version::draft($environmentId, (int) $this->user()['id']) : null) ?: Version::current($environmentId);
        if (!$version) { flash('error', 'Chưa có phiên bản cấu hình để xuất.'); redirect('versions'); }
        $data = (new ImportExportService())->export($projectId, $environmentId, (int) $version['id']);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="feature-flags-' . $environment['key'] . '-v' . $version['version_number'] . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function import()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $this->requireEditor($projectId);
        try {
            $environmentId = Auth::environmentId($projectId);
            $this->requireTestEnvironment($projectId, $environmentId);
            $payload = ImportConfigRequest::fromGlobals();
            $version = (new ImportExportService())->importDraft($payload, $projectId, $environmentId, $user['id'], trim((string) ($_POST['reason'] ?? 'Nhập JSON')));
            flash('success', 'Cấu hình hợp lệ đã được lưu vào bản nháp thử nghiệm v' . $version['version_number'] . '.');
        } catch (\Throwable $exception) { flash('error', 'Không nhập được cấu hình: ' . $exception->getMessage()); }
        redirect('audit');
    }

    public function importLogs()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $this->requireEditor($projectId);
        try {
            $logs = ImportLogsRequest::fromGlobals();
            $environmentId = Auth::environmentId($projectId);
            $pdo = Database::connection();
            Database::transaction(function ($pdo) use ($logs, $projectId, $environmentId, $user) {
                $insert = $pdo->prepare('INSERT INTO project_logs (project_id,environment_id,uploaded_by,occurred_at,level,source,message,context,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
                foreach ($logs as $log) $insert->execute([(int) $projectId, $environmentId ? (int) $environmentId : null, (int) $user['id'], $log['occurred_at'], $log['level'], $log['source'], $log['message'], $log['context']]);
                (new \App\Services\AuditLogService())->record($projectId, $user['id'], 'logs.imported', 'logs', null, null, ['count' => count($logs)], 'Nhập file log', $environmentId);
            });
            flash('success', 'Đã nhận và lưu ' . count($logs) . ' dòng log. Log được lưu bổ sung, không sửa log lịch sử.');
        } catch (\Throwable $exception) { flash('error', 'Không nhập được log: ' . $exception->getMessage()); }
        redirect('audit');
    }

    public function exportLogs()
    {
        $this->user();
        $projectId = $this->projectId();
        if (!Auth::canViewAudit($projectId)) { http_response_code(403); exit('Admin chưa cấp quyền tải nhật ký.'); }
        $pdo = Database::connection();
        $rows = [];
        $audit = $pdo->prepare('SELECT created_at,action,subject_type,subject_key,reason,old_values,new_values FROM audit_logs WHERE project_id=? ORDER BY id DESC LIMIT 100000');
        $audit->execute([$projectId]);
        foreach ($audit->fetchAll() as $row) $rows[] = [$row['created_at'],'audit','info',$row['action'],$row['subject_type'] . ($row['subject_key'] ? ':' . $row['subject_key'] : ''),trim(($row['reason'] ?? '') . ' ' . json_encode(['old' => $row['old_values'], 'new' => $row['new_values']], JSON_UNESCAPED_UNICODE))];
        $decisions = $pdo->prepare('SELECT created_at,environment_id,version_id,user_id,flag_key,result,reason_code,matched_rule_id FROM decision_logs WHERE project_id=? ORDER BY id DESC LIMIT 100000');
        $decisions->execute([$projectId]);
        foreach ($decisions->fetchAll() as $row) $rows[] = [$row['created_at'],'decision',((int) $row['result']) ? 'info' : 'notice',$row['reason_code'],$row['flag_key'],'user=' . $row['user_id'] . '; enabled=' . ((int) $row['result'] ? 'true' : 'false') . '; environment_id=' . $row['environment_id'] . '; version_id=' . ($row['version_id'] ?? '') . '; rule_id=' . ($row['matched_rule_id'] ?? '')];
        $uploaded = $pdo->prepare('SELECT created_at,occurred_at,level,source,message,context FROM project_logs WHERE project_id=? ORDER BY id DESC LIMIT 100000');
        $uploaded->execute([$projectId]);
        foreach ($uploaded->fetchAll() as $row) $rows[] = [$row['occurred_at'] ?: $row['created_at'],'uploaded',$row['level'],$row['source'] ?: 'uploaded-log',$row['message'],$row['context'] ?? ''];
        usort($rows, function ($a, $b) { return strcmp((string) $b[0], (string) $a[0]); });
        $project = \App\Models\Project::find($projectId);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="logs-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $project['key'] ?? 'project') . '.csv"');
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['timestamp','type','level','source','subject','message_or_context']);
        foreach ($rows as $row) fputcsv($output, array_map(function ($value) { return $this->safeCsvCell($value); }, $row));
        fclose($output);
    }

    public function audit()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        if (!Auth::canViewAudit($projectId)) { http_response_code(403); exit('Admin chưa cấp quyền xem nhật ký cho tài khoản này.'); }
        $filters = [
            'actor_user_id' => $_GET['actor_user_id'] ?? null,
            'environment_id' => $_GET['environment_id'] ?? null,
            'version_id' => $_GET['version_id'] ?? null,
            'subject_key' => $_GET['subject_key'] ?? null,
            'from' => $_GET['from'] ?? null,
            'to' => $_GET['to'] ?? null,
        ];
        $audit = \App\Models\AuditLog::forProject($projectId, $filters);
        $environmentId = Auth::environmentId($projectId);
        $envs = Environment::forProject($projectId);
        $decisions = [];
        if (!empty($_GET['show_decisions'])) {
            $query = Database::connection()->prepare('SELECT d.*,e.name environment_name,v.version_number FROM decision_logs d JOIN environments e ON e.id=d.environment_id LEFT JOIN versions v ON v.id=d.version_id WHERE d.project_id=? ORDER BY d.id DESC LIMIT 300');
            $query->execute([$projectId]);
            $decisions = $query->fetchAll();
        }
        $logsQuery = Database::connection()->prepare('SELECT l.*,u.name uploader_name FROM project_logs l LEFT JOIN users u ON u.id=l.uploaded_by WHERE l.project_id=? ORDER BY l.id DESC LIMIT 100');
        $logsQuery->execute([$projectId]);
        $projectLogs = $logsQuery->fetchAll();
        $isTestEnvironment = $this->isTestEnvironment($projectId, $environmentId);
        $this->render('audit', compact('user','projectId','audit','envs','environmentId','filters','decisions','projectLogs','isTestEnvironment'));
    }

    private function safeCsvCell($value)
    {
        $value = (string) $value;
        return preg_match('/^[=+@\-\t\r]/', $value) ? "'" . $value : $value;
    }
}
