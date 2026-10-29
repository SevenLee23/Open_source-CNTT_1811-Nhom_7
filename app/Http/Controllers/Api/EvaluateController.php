<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateEnvironmentKey;
use App\Http\Requests\EvaluateRequest;
use App\Services\FlagEvaluator;
use App\Support\Database;

final class EvaluateController extends Controller
{
    public function evaluate()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); return; }
        $context = (new AuthenticateEnvironmentKey())->authenticate();
        if (!$context) return $this->json(['success' => false, 'message' => 'A valid environment API key is required.'], 401);
        try { $input = EvaluateRequest::validate($this->inputJson()); }
        catch (\Throwable $exception) { return $this->json(['success' => false, 'message' => $exception->getMessage()], 422); }
        if (empty($context['environment_id'])) {
            $environmentKey = $input['environment'] !== '' ? $input['environment'] : 'staging';
            $environment = Database::connection()->prepare('SELECT id,`key` FROM environments WHERE project_id=? AND `key`=? LIMIT 1');
            $environment->execute([(int) $context['project_id'], $environmentKey]);
            $selected = $environment->fetch();
            if (!$selected && $input['environment'] === '') {
                $environment = Database::connection()->prepare('SELECT id,`key` FROM environments WHERE project_id=? ORDER BY id LIMIT 1');
                $environment->execute([(int) $context['project_id']]);
                $selected = $environment->fetch();
            }
            if (!$selected) return $this->json(['success' => false, 'message' => 'Unknown environment for this account API key.'], 422);
            $context['environment_id'] = (int) $selected['id'];
            $context['environment_key'] = $selected['key'];
        } elseif ($input['environment'] !== '' && $input['environment'] !== $context['environment_key']) {
            return $this->json(['success' => false, 'message' => 'The API key is restricted to environment ' . $context['environment_key'] . '.'], 403);
        }
        try {
            $result = (new FlagEvaluator())->evaluate((int) $context['project_id'], (int) $context['environment_id'], $input['user_id'], $input['flags']);
            if (!$result['version']) return $this->json(['success' => false, 'message' => 'No published configuration is available for this environment.'], 503);
            $known = $result['results'];
            $requested = [];
            foreach ($input['flags'] as $key) {
                if (isset($known[$key])) {
                    $requested[$key] = $known[$key];
                } else {
                    $requested[$key] = ['value' => false, 'reason' => 'unknown_flag', 'matched_rule_id' => null, 'bucket' => null];
                    $pdo = Database::connection();
                    $pdo->prepare('INSERT INTO decision_logs (project_id,environment_id,version_id,user_id,flag_key,result,reason_code,created_at) VALUES (?,?,?,?,?,0,?,NOW())')
                        ->execute([(int) $context['project_id'], (int) $context['environment_id'], $result['version_id'] ?? null, $input['user_id'], $key, 'unknown_flag']);
                }
            }
            return $this->json(['success' => true, 'environment' => $context['environment_key'], 'version' => $result['version'], 'results' => $requested]);
        } catch (\Throwable $exception) {
            return $this->json(['success' => false, 'message' => 'Evaluation failed.'], 500);
        }
    }
}
