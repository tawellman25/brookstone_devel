<?php

declare(strict_types=1);

namespace Drupal\bos_wo_intake\Plugin\rest\resource;

use Drupal\bos_wo_intake\Service\WorkOrderIntakeService;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * WO-intake endpoint (Cowork Connect).
 *
 * Thin transport skin: authenticate (custom X-API-KEY provider), read JSON,
 * delegate to WorkOrderIntakeService, serialize. No business logic here.
 *
 * @RestResource(
 *   id = "wo_intake",
 *   label = @Translation("Work Order Intake (Cowork Connect)"),
 *   uri_paths = {
 *     "create" = "/api/wo-intake"
 *   }
 * )
 */
final class WoIntakeResource extends ResourceBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    private readonly WorkOrderIntakeService $intake,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('bos_wo_intake'),
      $container->get('bos_wo_intake.intake'),
    );
  }

  /**
   * POST /api/wo-intake  { "property_id": <int>, "service_term_id": <int> }.
   */
  public function post($data = NULL): ModifiedResourceResponse {
    if (!is_array($data)) {
      return $this->fail('invalid_body', 'Request body must be a JSON object.', 400);
    }

    $propertyId = $this->intId($data['property_id'] ?? NULL);
    $serviceTermId = $this->intId($data['service_term_id'] ?? NULL);
    if ($propertyId === NULL || $serviceTermId === NULL) {
      return $this->fail('missing_ids', 'Both property_id and service_term_id are required positive integers.', 400);
    }

    $result = $this->intake->createBareWorkOrder($propertyId, $serviceTermId);
    $status = (int) ($result['http'] ?? 200);
    unset($result['http']);

    // ModifiedResourceResponse is uncacheable — correct for a write endpoint.
    return new ModifiedResourceResponse($result, $status);
  }

  /**
   * Coerce a value to a positive int id, or NULL if not one.
   */
  private function intId($value): ?int {
    if (is_int($value) && $value > 0) {
      return $value;
    }
    if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
      return (int) $value;
    }
    return NULL;
  }

  /**
   * Build a structured error response matching the service's error shape.
   */
  private function fail(string $code, string $message, int $status): ModifiedResourceResponse {
    return new ModifiedResourceResponse([
      'success' => FALSE,
      'error' => ['code' => $code, 'message' => $message],
    ], $status);
  }

}
