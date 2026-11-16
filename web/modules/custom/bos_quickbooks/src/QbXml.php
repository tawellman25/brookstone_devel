<?php

declare(strict_types=1);

namespace Drupal\bos_quickbooks;

/**
 * qbXML — builds the list queries BOS sends and parses what QuickBooks answers.
 *
 * Phase 0 speaks three requests: ClassQueryRq, CustomerQueryRq and
 * ItemQueryRq (the latter two paged with QuickBooks iterators). Every record
 * is normalised to one flat array keyed the way QbListMirror stores it.
 * Everything that arrives is untrusted input from the connector: parsed with
 * DOM, no entity expansion, no network.
 */
final class QbXml {

  /**
   * Bundle → the qbXML object name.
   */
  public const LISTS = ['class' => 'Class', 'customer' => 'Customer', 'item' => 'Item'];

  /**
   * Lists whose query supports iterator paging.
   */
  public const ITERATES = ['customer' => TRUE, 'item' => TRUE, 'class' => FALSE];

  /**
   * Wraps one or more *Rq elements in the qbXML envelope.
   */
  public function envelope(string $requests, string $version): string {
    $version = preg_match('/^\d+\.\d+$/', $version) ? $version : '16.0';
    return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
      . '<?qbxml version="' . $version . '"?>' . "\n"
      . '<QBXML><QBXMLMsgsRq onError="stopOnError">' . $requests . '</QBXMLMsgsRq></QBXML>';
  }

  /**
   * A full-list query (active + inactive) for one list, one iterator page.
   *
   * @param string $bundle
   *   class | customer | item.
   * @param string $requestId
   *   Echoed back by QuickBooks as requestID — used to pair the response.
   * @param int $max
   *   Records per page (ignored for a list that does not iterate).
   * @param string|null $iteratorId
   *   NULL for the first page; the iteratorID QuickBooks returned to continue.
   */
  public function listQuery(string $bundle, string $requestId, int $max, ?string $iteratorId): string {
    $object = self::LISTS[$bundle] ?? throw new \InvalidArgumentException("Unknown list $bundle");
    $attrs = ' requestID="' . htmlspecialchars($requestId, ENT_XML1) . '"';
    $body = '';
    if (self::ITERATES[$bundle]) {
      $attrs .= $iteratorId === NULL
        ? ' iterator="Start"'
        : ' iterator="Continue" iteratorID="' . htmlspecialchars($iteratorId, ENT_XML1) . '"';
      $body .= '<MaxReturned>' . max(1, $max) . '</MaxReturned>';
    }
    $body .= '<ActiveStatus>All</ActiveStatus>';
    return "<{$object}QueryRq{$attrs}>{$body}</{$object}QueryRq>";
  }

  /**
   * Parses a QBXMLMsgsRs carrying one list-query response.
   *
   * @return array{request_id:string,bundle:?string,status_code:int,status_severity:string,status_message:string,iterator_id:?string,iterator_remaining:int,records:array}
   *
   * @throws \RuntimeException
   *   When the XML cannot be parsed or holds no *QueryRs element.
   */
  public function parse(string $xml): array {
    $doc = $this->dom($xml);
    $rs = NULL;
    $bundle = NULL;
    foreach (self::LISTS as $b => $object) {
      $nodes = $doc->getElementsByTagName($object . 'QueryRs');
      if ($nodes->length) {
        $rs = $nodes->item(0);
        $bundle = $b;
        break;
      }
    }
    if (!$rs instanceof \DOMElement) {
      throw new \RuntimeException('No Class/Customer/ItemQueryRs element in the response.');
    }
    $out = [
      'request_id' => $rs->getAttribute('requestID'),
      'bundle' => $bundle,
      'status_code' => (int) $rs->getAttribute('statusCode'),
      'status_severity' => $rs->getAttribute('statusSeverity'),
      'status_message' => $rs->getAttribute('statusMessage'),
      'iterator_id' => $rs->hasAttribute('iteratorID') ? $rs->getAttribute('iteratorID') : NULL,
      'iterator_remaining' => (int) $rs->getAttribute('iteratorRemainingCount'),
      'records' => [],
    ];
    foreach ($rs->childNodes as $child) {
      if (!$child instanceof \DOMElement || !str_ends_with($child->tagName, 'Ret')) {
        continue;
      }
      $out['records'][] = match ($bundle) {
        'customer' => $this->customer($child),
        'item' => $this->item($child),
        default => $this->common($child),
      };
    }
    return $out;
  }

  /**
   * Parses the connector's strHCPResponse (host / company / preferences).
   *
   * @return array{company_name:string,product:string,qbxml_versions:string}
   */
  public function parseHcp(string $xml): array {
    $out = ['company_name' => '', 'product' => '', 'qbxml_versions' => ''];
    if (trim($xml) === '') {
      return $out;
    }
    try {
      $doc = $this->dom($xml);
    }
    catch (\RuntimeException) {
      return $out;
    }
    $company = $doc->getElementsByTagName('CompanyRet')->item(0);
    if ($company instanceof \DOMElement) {
      $out['company_name'] = $this->text($company, 'CompanyName');
    }
    $host = $doc->getElementsByTagName('HostRet')->item(0);
    if ($host instanceof \DOMElement) {
      $out['product'] = trim($this->text($host, 'ProductName') . ' ' . $this->text($host, 'MajorVersion') . '.' . $this->text($host, 'MinorVersion'), ' .');
      $versions = [];
      foreach ($host->getElementsByTagName('SupportedQBXMLVersion') as $v) {
        $versions[] = $v->textContent;
      }
      $out['qbxml_versions'] = implode(' ', $versions);
    }
    return $out;
  }

  /**
   * Fields every list object shares.
   */
  private function common(\DOMElement $ret): array {
    $parent = $this->child($ret, 'ParentRef');
    return [
      'list_id' => $this->text($ret, 'ListID'),
      'time_created' => $this->utc($this->text($ret, 'TimeCreated')),
      'time_modified' => $this->utc($this->text($ret, 'TimeModified')),
      'edit_sequence' => $this->text($ret, 'EditSequence'),
      'name' => $this->text($ret, 'Name'),
      'full_name' => $this->text($ret, 'FullName') ?: $this->text($ret, 'Name'),
      'is_active' => strtolower($this->text($ret, 'IsActive') ?: 'true') === 'true',
      'sublevel' => (int) $this->text($ret, 'Sublevel'),
      'parent_list_id' => $parent ? $this->text($parent, 'ListID') : '',
    ];
  }

  private function customer(\DOMElement $ret): array {
    $rec = $this->common($ret);
    $bill = $this->child($ret, 'BillAddress');
    $lines = [];
    if ($bill) {
      foreach (['Addr1', 'Addr2', 'Addr3', 'Addr4', 'Addr5'] as $k) {
        $v = $this->text($bill, $k);
        if ($v !== '') {
          $lines[] = $v;
        }
      }
      $city = trim($this->text($bill, 'City') . ', ' . $this->text($bill, 'State') . ' ' . $this->text($bill, 'PostalCode'), ', ');
      if ($city !== '') {
        $lines[] = $city;
      }
    }
    $type = $this->child($ret, 'CustomerTypeRef');
    $terms = $this->child($ret, 'TermsRef');
    return $rec + [
      'first_name' => $this->text($ret, 'FirstName'),
      'last_name' => $this->text($ret, 'LastName'),
      'company_name' => $this->text($ret, 'CompanyName'),
      'customer_type' => $type ? $this->text($type, 'FullName') : '',
      'terms' => $terms ? $this->text($terms, 'FullName') : '',
      'bill_address' => implode("\n", $lines),
    ];
  }

  private function item(\DOMElement $ret): array {
    $rec = $this->common($ret);
    // ItemServiceRet → Service, ItemOtherChargeRet → OtherCharge, …
    $type = preg_replace('/^Item(.*)Ret$/', '$1', $ret->tagName);
    $sop = $this->child($ret, 'SalesOrPurchase');
    $sap = $this->child($ret, 'SalesAndPurchase');
    $desc = '';
    $price = '';
    $account = '';
    if ($sop) {
      $desc = $this->text($sop, 'Desc');
      $price = $this->text($sop, 'Price');
      $acc = $this->child($sop, 'AccountRef');
      $account = $acc ? $this->text($acc, 'FullName') : '';
    }
    elseif ($sap) {
      $desc = $this->text($sap, 'SalesDesc');
      $price = $this->text($sap, 'SalesPrice');
      $acc = $this->child($sap, 'IncomeAccountRef');
      $account = $acc ? $this->text($acc, 'FullName') : '';
    }
    else {
      $desc = $this->text($ret, 'SalesDesc') ?: $this->text($ret, 'ItemDesc');
      $price = $this->text($ret, 'SalesPrice') ?: $this->text($ret, 'DiscountRate') ?: $this->text($ret, 'TaxRate');
      $acc = $this->child($ret, 'IncomeAccountRef') ?: $this->child($ret, 'AccountRef');
      $account = $acc ? $this->text($acc, 'FullName') : '';
    }
    return $rec + [
      'item_type' => $type,
      'income_account' => $account,
      'price' => is_numeric($price) ? number_format((float) $price, 2, '.', '') : NULL,
      'description' => $desc,
    ];
  }

  private function dom(string $xml): \DOMDocument {
    $doc = new \DOMDocument();
    $prev = libxml_use_internal_errors(TRUE);
    try {
      $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
      $errors = libxml_get_errors();
      libxml_clear_errors();
    }
    finally {
      libxml_use_internal_errors($prev);
    }
    if (!$ok) {
      $first = $errors ? trim($errors[0]->message) : 'unknown error';
      throw new \RuntimeException('Unparsable XML: ' . $first);
    }
    return $doc;
  }

  /**
   * Text of the first DIRECT child element with that name, or ''.
   */
  private function text(\DOMElement $el, string $name): string {
    $child = $this->child($el, $name);
    return $child ? trim($child->textContent) : '';
  }

  private function child(\DOMElement $el, string $name): ?\DOMElement {
    foreach ($el->childNodes as $c) {
      if ($c instanceof \DOMElement && $c->tagName === $name) {
        return $c;
      }
    }
    return NULL;
  }

  /**
   * QuickBooks "2026-01-05T10:22:33-07:00" → "2026-01-05T17:22:33" (UTC), or NULL.
   */
  private function utc(string $value): ?string {
    if ($value === '') {
      return NULL;
    }
    try {
      $dt = new \DateTimeImmutable($value);
    }
    catch (\Exception) {
      return NULL;
    }
    return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
  }

}
