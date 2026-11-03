<?php
/**
 * Unraid Docker Folders - Service Widgets API
 *
 * GET ?ids=a,b returns the service widget of each container that has
 * homepage.widget.* labels. The request names container ids only: the
 * server reads the labels itself and calls the service, so the key never
 * reaches the browser. See classes/ServiceWidgets.php.
 *
 * @package UnraidDockerModern
 */

require_once dirname(__DIR__) . '/include/config.php';
require_once dirname(__DIR__) . '/include/auth.php';
require_once dirname(__DIR__) . '/classes/DockerClient.php';
require_once dirname(__DIR__) . '/classes/ServiceWidgets.php';

header('Content-Type: application/json');

requireAuth();

/** One poll asks for the visible cards, which is far fewer than this. */
const SERVICE_WIDGET_MAX_IDS = 100;

$method = $_SERVER['REQUEST_METHOD'];

try {
  switch ($method) {
    case 'GET':
      handleGet();
      break;

    case 'OPTIONS':
      http_response_code(200);
      exit();

    default:
      errorResponse('Method not allowed', 405);
  }
} catch (Exception $e) {
  error_log('Service widgets API error: ' . $e->getMessage());
  errorResponse('Failed to load service widgets', 500);
}

function handleGet()
{
  $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($_GET['ids'] ?? ''))))));
  if (empty($ids)) {
    errorResponse('Missing required parameter: ids', 400);
  }
  // Container ids and names only. They go into a Docker API path.
  foreach ($ids as $id) {
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $id)) {
      errorResponse('Invalid container id', 400);
    }
  }
  $ids = array_slice($ids, 0, SERVICE_WIDGET_MAX_IDS);

  $widgets = (new ServiceWidgets())->forContainers($ids, new DockerClient());
  jsonResponse(['widgets' => (object) $widgets]);
}
