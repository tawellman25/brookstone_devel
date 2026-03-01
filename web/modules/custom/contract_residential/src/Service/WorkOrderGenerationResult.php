<?php

namespace Drupal\contract_residential\Service;

/**
 * Result container for Work Order generation.
 */
final class WorkOrderGenerationResult {

  private bool $dryRun;

  private int $created = 0;
  private int $wouldCreate = 0;
  private int $skipped = 0;

  /**
   * @var string[]
   */
  private array $messages = [];

  public function __construct(bool $dry_run = FALSE) {
    $this->dryRun = $dry_run;
  }

  public function isDryRun(): bool {
    return $this->dryRun;
  }

  public function addCreated(int $count = 1): void {
    $this->created += $count;
  }

  public function addWouldCreate(int $count = 1): void {
    $this->wouldCreate += $count;
  }

  public function addSkipped(int $count = 1): void {
    $this->skipped += $count;
  }

  public function addMessage(string $message): void {
    $message = trim($message);
    if ($message !== '') {
      $this->messages[] = $message;
    }
  }

  public function getCreated(): int {
    return $this->created;
  }

  public function getWouldCreate(): int {
    return $this->wouldCreate;
  }

  public function getSkipped(): int {
    return $this->skipped;
  }

  /**
   * @return string[]
   */
  public function getMessages(): array {
    return $this->messages;
  }

  public function hasErrors(): bool {
    foreach ($this->messages as $m) {
      if (stripos($m, 'ERROR') !== FALSE || stripos($m, 'REFUSED') !== FALSE) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
