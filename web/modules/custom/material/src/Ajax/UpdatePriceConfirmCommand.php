<?php

namespace Drupal\material\Ajax;

use Drupal\Core\Ajax\CommandInterface;

class UpdatePriceConfirmCommand implements CommandInterface {
  public function render() {
    return [
      'command' => 'materialUpdatePriceConfirm',
      'method' => NULL,
    ];
  }
}