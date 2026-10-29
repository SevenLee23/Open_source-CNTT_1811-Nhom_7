<?php
namespace App\Http\Controllers;

use App\Models\Environment;
use App\Models\Version;
use App\Services\RollbackService;
use App\Services\VersionService;
use App\Support\Auth;
use App\Support\Database;

final class VersionController extends Controller
{
    public function index()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $environmentId = Auth::environmentId($projectId);
        $environment = $environmentId ? Environment::find($environmentId) : null;
        $versions = $environmentId ? Version::forEnvironment($environmentId) : [];
        $draft = $environmentId ? Version::draft($environmentId, $user['id']) : null;
        $current = $environmentId ? Version::current($environmentId) : null;
        $isTestEnvironment = $this->isTestEnvironment($projectId, $environmentId);
        $this->render('versions', compact('user','projectId','environment','versions','draft','current','isTestEnvironment'));
    }

    public function createDraft()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $this->requireEditor($projectId);
        $environmentId = Auth::environmentId($projectId);
        $this->requireTestEnvironment($projectId, $environmentId);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        try {
            $draft = (new VersionService())->createDraft($environmentId, $user['id'], null, $reason ?: 'Tạo bản nháp');
            flash('success', 'Đang chỉnh sửa bản nháp v' . $draft['version_number'] . '.');
        } catch (\Throwable $exception) { flash('error', $exception->getMessage()); }
        redirect('versions');
    }

    public function publish()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $this->requireEditor($projectId);
        $versionId = (int) ($_POST['version_id'] ?? 0);
        $version = Version::find($versionId);
        if (!$version || (int) $version['project_id'] !== $projectId) { http_response_code(404); exit('Không tìm thấy bản nháp.'); }
        $this->requireTestEnvironment($projectId, (int) $version['environment_id']);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '') { flash('error', 'Ghi lý do phát hành trước khi tiếp tục.'); redirect('versions'); }
        try {
            (new VersionService())->publish($versionId, $user['id'], $reason);
            flash('success', 'Đã phát hành v' . $version['version_number'] . '.');
        } catch (\Throwable $exception) { flash('error', $exception->getMessage()); }
        redirect('versions');
    }

    public function compare()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $this->requireEditor($projectId);
        try {
            $result = (new VersionService())->compare((int) ($_POST['left_version_id'] ?? 0), (int) ($_POST['right_version_id'] ?? 0));
            if ((int) $result['left']['project_id'] !== $projectId) throw new \DomainException('Phiên bản không thuộc dự án hiện tại.');
            $this->render('version-compare', ['user' => $user, 'projectId' => $projectId, 'result' => $result]);
        } catch (\Throwable $exception) { flash('error', $exception->getMessage()); redirect('versions'); }
    }

    public function rollback()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $this->requireEditor($projectId);
        $targetId = (int) ($_POST['version_id'] ?? 0);
        $target = Version::find($targetId);
        if (!$target || (int) $target['project_id'] !== $projectId) { http_response_code(404); exit('Không tìm thấy phiên bản đích.'); }
        $this->requireTestEnvironment($projectId, (int) $target['environment_id']);
        try {
            $created = (new RollbackService())->rollback((int) $target['environment_id'], $targetId, $user['id'], trim((string) ($_POST['reason'] ?? '')));
            flash('success', 'Đã tạo và phát hành phiên bản rollback v' . $created['version_number'] . '.');
        } catch (\Throwable $exception) { flash('error', $exception->getMessage()); }
        redirect('versions');
    }
}
