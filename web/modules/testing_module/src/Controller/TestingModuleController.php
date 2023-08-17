<?php

namespace Drupal\testing_module\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Returns responses for Testing Module routes.
 */
class TestingModuleController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function build() {

    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('It works!'),
    ];

    return $build;
  }

}
