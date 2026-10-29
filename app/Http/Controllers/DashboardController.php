<?php
namespace App\Http\Controllers;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Version;
use App\Support\Auth;
use App\Support\Database;

final class DashboardController extends Controller
{
    public function index()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $project = Project::findForUser($projectId, $user['id']);
        if (!$project) redirect('projects');
        $environments = Environment::forProject($projectId);
        $environmentId = Auth::environmentId($projectId);
        $environment = null;
        foreach ($environments as $item) if ((int) $item['id'] === $environmentId) $environment = $item;
        $pdo = Database::connection();
        $count = $pdo->prepare('SELECT COUNT(*) FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE f.project_id=? AND f.is_active=1');
        $count->execute([$projectId]);
        $flagCount = (int) $count->fetchColumn();
        $currentVersion = $environmentId ? Version::current($environmentId) : null;
        $flags = [];
        if ($currentVersion) {
            $statement = $pdo->prepare('SELECT f.`key`,f.name,vf.default_value,vf.kill_switch,vf.rollout_percentage FROM version_flags vf JOIN flags f ON f.id=vf.flag_id JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE vf.version_id=? AND f.is_active=1 ORDER BY f.`key` LIMIT 6');
            $statement->execute([(int) $currentVersion['id']]);
            $flags = $statement->fetchAll();
        }
        $versions = $environmentId ? Version::forEnvironment($environmentId) : [];
        $audit = [];
        if (Auth::canViewAudit($projectId)) $audit = \App\Models\AuditLog::forProject($projectId, ['environment_id' => $environmentId]);
        $this->render('dashboard', compact('user','project','projectId','environments','environment','environmentId','flagCount','currentVersion','flags','versions','audit'));
    }

    public function evaluate()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $environmentId = Auth::environmentId($projectId);
        $environment = $environmentId ? Environment::find($environmentId) : null;
        $flags = \App\Models\Flag::forProject($projectId);
        $result = null;
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $flagKey = trim((string) ($_POST['flag_key'] ?? ''));
            $userId = trim((string) ($_POST['user_id'] ?? ''));
            try {
                $evaluator = new \App\Services\FlagEvaluator();
                $result = $evaluator->evaluate($projectId, $environmentId, $userId, [$flagKey]);
            } catch (\Throwable $exception) { $result = ['error' => $exception->getMessage()]; }
        }
        $this->render('evaluation', compact('user','projectId','environment','flags','result'));
    }
}
