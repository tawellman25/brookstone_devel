<?php

namespace Drupal\admin_calendar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the scheduling calendar page.
 */
class AdminCalendarController extends ControllerBase {

  protected Connection $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function page(): array {
    $build = [
      '#theme' => 'admin_calendar_page',
      '#departments' => $this->getDepartments(),
      '#teammates'   => $this->getTeammates(),
      '#statuses'    => $this->getStatusOptions(),
      '#attached' => [
        'library' => ['admin_calendar/admin_calendar'],
        'drupalSettings' => [
          'adminCalendar' => [
            'eventsUrl'   => '/admin/scheduling/calendar/events',
            'defaultView' => 'dayGridMonth',
          ],
        ],
      ],
    ];
    return $build;
  }

  protected function getDepartments(): array {
    $query = $this->database->query("
      SELECT d.id, d.title
      FROM department_field_data d
      ORDER BY d.title
    ");
    $departments = ['' => '— All Departments —'];
    foreach ($query->fetchAll() as $row) {
      $departments[$row->id] = $row->title;
    }
    return $departments;
  }

  protected function getTeammates(): array {
    $query = $this->database->query("
      SELECT u.uid,
        COALESCE(fn.field_first_name_value, '') AS first_name,
        COALESCE(ln.field_last_name_value, '') AS last_name
      FROM users_field_data u
      JOIN user__roles r ON r.entity_id = u.uid AND r.roles_target_id = 'teammates'
      LEFT JOIN profile p ON p.uid = u.uid AND p.type = 'teammate_profile' AND p.status = 1
      LEFT JOIN profile__field_first_name fn ON fn.entity_id = p.profile_id
      LEFT JOIN profile__field_last_name ln ON ln.entity_id = p.profile_id
      WHERE u.status = 1
      ORDER BY ln.field_last_name_value, fn.field_first_name_value
    ");
    $teammates = ['' => '— All Teammates —'];
    foreach ($query->fetchAll() as $row) {
      $display = trim($row->first_name . ' ' . $row->last_name);
      if ($display) {
        $teammates[$row->uid] = $display;
      }
    }
    return $teammates;
  }

  protected function getStatusOptions(): array {
    return [
      ''     => '— Active (default) —',
      '1091' => 'Scheduled',
      '1090' => 'Assigned',
      '1092' => 'In Progress',
      '1093' => 'Needs Parts',
      '1094' => 'Parts Ordered',
      '1096' => 'Needs Access',
      '1097' => 'Complete',
      '1281' => 'Invoiced',
      '1504' => 'Paid',
      '1098' => 'Canceled',
      'all'  => 'All Statuses',
    ];
  }

}
