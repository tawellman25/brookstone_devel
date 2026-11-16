<?php

declare(strict_types=1);

namespace Drupal\bos_quickbooks\Controller;

use Drupal\bos_quickbooks\QbMonitor;
use Drupal\bos_quickbooks\QbwcSession;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * /admin/office/quickbooks — status page; the .qwc download.
 */
final class AdminController extends ControllerBase {

  public function __construct(
    private readonly QbMonitor $monitor,
    private readonly QbwcSession $session,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bos_quickbooks.monitor'),
      $container->get('bos_quickbooks.session'),
    );
  }

  public function status(): array {
    $f = $this->monitor->inspect();
    $build = ['#cache' => ['max-age' => 0]];
    $admin = $this->currentUser()->hasPermission('administer quickbooks integration');

    if (!$f['password_set'] && $admin) {
      $this->messenger()->addWarning($this->t('No Web Connector password is set yet — the connector cannot authenticate. Set one under <a href=":url">Settings</a>.', [':url' => Url::fromRoute('bos_quickbooks.settings')->toString()]));
    }

    // ── Connector ──────────────────────────────────────────────────────
    if ($f['never']) {
      $state = $this->t('Never synced — the Web Connector has not completed a run yet.');
      $class = 'warning';
    }
    elseif ($f['stale']) {
      $state = $this->t('STALE — the last complete sync is older than @h hours. Invoicing is blocked until a clean run lands.', ['@h' => $f['stale_hours']]);
      $class = 'error';
    }
    else {
      $state = $this->t('Current — a complete sync finished within the last @h hours.', ['@h' => $f['stale_hours']]);
      $class = 'ok';
    }
    $rows = [
      [$this->t('Mirror'), ['data' => ['#markup' => '<strong>' . $state . '</strong>'], 'class' => ["bos-qb-$class"]]],
      [$this->t('Last complete sync'), $f['last_full_sync'] ? $this->t('@when — @text (@s s, @n requests)', ['@when' => _bos_quickbooks_date((int) ($f['last_full_sync']['at'] ?? 0)), '@text' => $f['last_full_sync']['text'] ?? '', '@s' => $f['last_full_sync']['duration'] ?? 0, '@n' => $f['last_full_sync']['requests'] ?? 0]) : $this->t('never')],
      [$this->t('Last contact from the connector'), $f['last_contact'] ? _bos_quickbooks_date($f['last_contact']) : $this->t('never')],
      [$this->t('Company file'), $f['company'] ? $this->t('@name — @file (@product, seen @when)', ['@name' => $f['company']['name'] ?: '?', '@file' => $f['company']['file'] ?: '?', '@product' => $f['company']['product'] ?: 'QuickBooks', '@when' => _bos_quickbooks_date((int) $f['company']['at'])]) : $this->t('not reported yet')],
      [$this->t('Connector version'), $f['connector_version'] ?: $this->t('unknown')],
      [$this->t('Run in progress'), $f['session'] ? $this->describeSession($f['session']) : $this->t('none')],
      [$this->t('Last run'), $f['last_run'] ? $this->t('@status at @when — @text', ['@status' => strtoupper((string) ($f['last_run']['status'] ?? '?')), '@when' => _bos_quickbooks_date((int) ($f['last_run']['at'] ?? 0)), '@text' => $f['last_run']['text'] ?? '']) : $this->t('none')],
      [$this->t('Last error'), $f['last_error'] ? $this->t('@when — @m', ['@when' => _bos_quickbooks_date((int) ($f['last_error']['at'] ?? 0)), '@m' => $f['last_error']['message'] ?? '']) : $this->t('none')],
      [$this->t('Alerts go to'), $f['recipient'] . ($f['alert_open'] ? ' — ' . $this->t('a stale alert is OPEN') : '')],
    ];
    $build['connector'] = [
      '#type' => 'details',
      '#title' => $this->t('Connector'),
      '#open' => TRUE,
      'table' => ['#type' => 'table', '#rows' => $rows, '#attributes' => ['class' => ['bos-qb-status']]],
    ];

    // ── Mirror counts ──────────────────────────────────────────────────
    $routes = ['customer' => 'view.qb_customers.page_1', 'item' => 'view.qb_items.page_1', 'class' => 'view.qb_classes.page_1'];
    $count_rows = [];
    foreach ($f['counts'] as $bundle => $c) {
      $count_rows[] = [
        ['data' => ['#type' => 'link', '#title' => ucfirst($bundle) . 's', '#url' => Url::fromRoute($routes[$bundle])]],
        number_format($c['total']),
        number_format($c['active']),
        number_format($c['missing']),
      ];
    }
    $build['mirror'] = [
      '#type' => 'details',
      '#title' => $this->t('Mirrored lists'),
      '#open' => TRUE,
      'table' => ['#type' => 'table', '#header' => [$this->t('List'), $this->t('Total'), $this->t('Active, present'), $this->t('Missing from QuickBooks')], '#rows' => $count_rows],
    ];

    // ── Matching ───────────────────────────────────────────────────────
    $m = $f['match_counts'];
    $labels = ['auto' => $this->t('Matched automatically'), 'manual' => $this->t('Matched by the office'), 'ambiguous' => $this->t('Ambiguous'), 'unmatched' => $this->t('Unmatched'), 'duplicate' => $this->t('Duplicate'), 'ignored' => $this->t('Ignored')];
    $match_rows = [];
    foreach ($labels as $k => $label) {
      $match_rows[] = [$label, number_format($m[$k] ?? 0)];
    }
    $to_review = ($m['unmatched'] ?? 0) + ($m['ambiguous'] ?? 0) + ($m['duplicate'] ?? 0);
    $build['matching'] = [
      '#type' => 'details',
      '#title' => $this->t('Customer ↔ BOS client matching (active, present customers)'),
      '#open' => TRUE,
      'intro' => ['#markup' => '<p>' . $this->t('Each QuickBooks customer is linked to one BOS client by ListID — the key invoicing uses. The matching pass links the ones whose names agree; the office resolves the rest on the Review list. <strong>@n to review.</strong>', ['@n' => number_format($to_review)]) . '</p>'],
      'table' => ['#type' => 'table', '#header' => [$this->t('Status'), $this->t('Customers')], '#rows' => $match_rows],
      'links' => [
        '#theme' => 'links',
        '#links' => array_filter([
          'review' => ['title' => $this->t('Open the Review list'), 'url' => Url::fromRoute('view.qb_customers.page_2')],
          'match' => $admin ? ['title' => $this->t('Run the matching pass'), 'url' => Url::fromRoute('bos_quickbooks.match')] : NULL,
        ]),
        '#attributes' => ['class' => ['action-links']],
      ],
    ];

    // ── Setup on the shop PC ───────────────────────────────────────────
    if ($admin) {
      $qwc = Url::fromRoute('bos_quickbooks.qwc')->toString();
      $settings = Url::fromRoute('bos_quickbooks.settings')->toString();
      $soap = Url::fromRoute('bos_quickbooks.soap', [], ['absolute' => TRUE])->toString();
      $build['setup'] = [
        '#type' => 'details',
        '#title' => $this->t('Setting up the Web Connector on the shop PC (one time, by an administrator)'),
        '#open' => $f['never'],
        'steps' => ['#markup' => '<ol>'
          . '<li>' . $this->t('Set the connector password under <a href=":s">Settings</a> (user name <code>@u</code>). Write it down — the connector asks for it once, when the application is added, and stores it itself.', [':s' => $settings, '@u' => $this->session->username()]) . '</li>'
          . '<li>' . $this->t('On the PC that has QuickBooks and the company file, make sure the <strong>QuickBooks Web Connector</strong> is installed (it ships with QuickBooks Desktop; the current build for Desktop 2024 is 34.0.10010.76, from the Intuit developer site).') . '</li>'
          . '<li>' . $this->t('<strong>Test against a restored COPY of the company file first.</strong> Open the copy in QuickBooks as Admin, single-user mode. Download <a href=":q">bos-quickbooks.qwc</a> and open it with the Web Connector (File ▸ App Management ▸ Update Web Services, or double-click the file).', [':q' => $qwc]) . '</li>'
          . '<li>' . $this->t('QuickBooks asks whether to allow the application. Choose <strong>“Yes, always; allow access even if QuickBooks is not running”</strong> and the <strong>Admin</strong> user, so the hourly sync runs with the file closed. Decline access to personal data (it is not needed).') . '</li>'
          . '<li>' . $this->t('In the Web Connector window tick the application, enter the password when prompted, press <strong>Update Selected</strong>. The first run pulls every customer, item and class — a few minutes. This page then shows the company file name, the counts and “Current”.') . '</li>'
          . '<li>' . $this->t('Satisfied with the copy? Remove the application from the Web Connector, open the REAL company file, add the same .qwc again and authorize it the same way. The ListIDs come from the real file from then on.') . '</li>'
          . '<li>' . $this->t('Leave the Web Connector running. It is a tray application, not a service: the PC must stay on and logged in (locked is fine) and must not sleep. Put the Web Connector in the Startup folder (or a Task Scheduler “At log on” task, NOT elevated) so it survives a reboot. Auto-run is every @m minutes, from the .qwc.', ['@m' => (int) ($this->config('bos_quickbooks.settings')->get('run_every_minutes') ?: 60)]) . '</li>'
          . '<li>' . $this->t('Then run the <a href=":m">matching pass</a> once and work the Review list.', [':m' => Url::fromRoute('bos_quickbooks.match')->toString()]) . '</li>'
          . '</ol>'
          . '<p>' . $this->t('Endpoint the connector polls: <code>@soap</code> (the PC needs HTTPS to this site). If the PC is off or the connector stops, nothing breaks — this page turns STALE after @h hours, an email goes out, and invoicing refuses to run until a clean sync lands.', ['@soap' => $soap, '@h' => $f['stale_hours']]) . '</p>',
        ],
      ];
    }
    return $build;
  }

  /**
   * Streams the .qwc file the Web Connector is configured from.
   */
  public function qwc(): Response {
    $settings = $this->config('bos_quickbooks.settings');
    $soap = Url::fromRoute('bos_quickbooks.soap', [], ['absolute' => TRUE])->toString();
    $support = Url::fromRoute('bos_quickbooks.support', [], ['absolute' => TRUE])->toString();
    $minutes = max(1, (int) ($settings->get('run_every_minutes') ?: 60));
    $e = static fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $xml = '<?xml version="1.0"?>' . "\r\n"
      . "<QBWCXML>\r\n"
      . '  <AppName>' . $e((string) ($settings->get('app_name') ?: 'BOS QuickBooks Sync')) . "</AppName>\r\n"
      . "  <AppID></AppID>\r\n"
      . '  <AppURL>' . $e($soap) . "</AppURL>\r\n"
      . '  <AppDescription>' . $e('Mirrors QuickBooks customers, items and classes into BOS (Brookstone Operating System) so invoices can be built from completed work orders.') . "</AppDescription>\r\n"
      . '  <AppSupport>' . $e($support) . "</AppSupport>\r\n"
      . '  <UserName>' . $e($this->session->username()) . "</UserName>\r\n"
      . '  <OwnerID>' . $e($this->session->ownerId()) . "</OwnerID>\r\n"
      . '  <FileID>' . $e($this->session->fileId()) . "</FileID>\r\n"
      . "  <QBType>QBFS</QBType>\r\n"
      . "  <Scheduler>\r\n"
      . '    <RunEveryNMinutes>' . $minutes . "</RunEveryNMinutes>\r\n"
      . "  </Scheduler>\r\n"
      . "  <IsReadOnly>false</IsReadOnly>\r\n"
      . "  <PersonalDataPref>pdpNotNeeded</PersonalDataPref>\r\n"
      . "  <UnattendedModePref>umpRequired</UnattendedModePref>\r\n"
      . "</QBWCXML>\r\n";
    $response = new Response($xml, 200, [
      'Content-Type' => 'application/xml; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="bos-quickbooks.qwc"',
    ]);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    return $response;
  }

  private function describeSession(array $s): string {
    $parts = [];
    foreach ($s['jobs'] ?? [] as $job) {
      $parts[] = sprintf('%s %s%s', $job['bundle'], $job['status'], $job['returned'] ? ' (' . $job['returned'] . ($job['remaining'] ? ' of ' . ($job['returned'] + $job['remaining']) : '') . ')' : '');
    }
    return sprintf('started %s, last call %s, %d request(s) — %s', _bos_quickbooks_date((int) $s['started']), _bos_quickbooks_date((int) $s['last_seen']), (int) ($s['requests'] ?? 0), implode('; ', $parts));
  }

}
