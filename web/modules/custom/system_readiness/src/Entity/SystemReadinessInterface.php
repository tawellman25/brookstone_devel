<?php

namespace Drupal\system_readiness\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;

interface SystemReadinessInterface extends ContentEntityInterface, EntityOwnerInterface {}

