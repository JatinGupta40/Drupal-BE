<?php

namespace Drupal\Tests\views_ui\Functional;

use Drupal\Component\Utility\Unicode;
use Drupal\Tests\views\Functional\Wizard\WizardTestBase;

/**
 * Tests the wizard.
 *
 * @group views_ui
 * @see \Drupal\views\Plugin\views\display\DisplayPluginBase
 * @see \Drupal\views\Plugin\views\display\PathPluginBase
 * @see \Drupal\views\Plugin\views\wizard\WizardPluginBase
 */
class WizardTest extends WizardTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests filling in the wizard with really long strings.
   */
  public function testWizardFieldLength() {
    $view = [];
    $view['label'] = $this->randomMachineName(256);
    $view['id'] = $this->randomMachineName(129);
    $view['page[create]'] = TRUE;
    $view['page[path]'] = $this->randomMachineName(255);
    $view['page[title]'] = $this->randomMachineName(256);
    $view['page[feed]'] = TRUE;
    $view['page[feed_properties][path]'] = $this->randomMachineName(255);
    $view['block[create]'] = TRUE;
    $view['block[title]'] = $this->randomMachineName(256);
    $view['rest_export[create]'] = TRUE;
    $view['rest_export[path]'] = $this->randomMachineName(255);

    $this->drupalGet('admin/structure/views/add');
    $this->submitForm($view, 'Save and edit');

    $this->assertSession()->pageTextContains('Machine-readable name cannot be longer than 128 characters but is currently 129 characters long.');
    $this->assertSession()->pageTextContains('Path cannot be longer than 254 characters but is currently 255 characters long.');
    $this->assertSession()->pageTextContains('Page title cannot be longer than 255 characters but is currently 256 characters long.');
    $this->assertSession()->pageTextContains('View name cannot be longer than 255 characters but is currently 256 characters long.');
    $this->assertSession()->pageTextContains('Feed path cannot be longer than 254 characters but is currently 255 characters long.');
    $this->assertSession()->pageTextContains('Block title cannot be longer than 255 characters but is currently 256 characters long.');
    $this->assertSession()->pageTextContains('REST export path cannot be longer than 254 characters but is currently 255 characters long.');

    $view['label'] = $this->randomMachineName(255);
    $view['id'] = $this->randomMachineName(128);
    $view['page[create]'] = TRUE;
    $view['page[path]'] = $this->randomMachineName(254);
    $view['page[title]'] = $this->randomMachineName(255);
    $view['page[feed]'] = TRUE;
    $view['page[feed_properties][path]'] = $this->randomMachineName(254);
    $view['block[create]'] = TRUE;
    $view['block[title]'] = $this->randomMachineName(255);
    $view['rest_export[create]'] = TRUE;
    $view['rest_export[path]'] = $this->randomMachineName(254);

    // Make sure the view saving was successful and the browser got redirected
    // to the edit page.
    $this->drupalGet('admin/structure/views/add');
    $this->submitForm($view, 'Save and edit');
    $this->assertSession()->addressEquals('admin/structure/views/view/' . $view['id']);
    // Assert that the page title is correctly truncated.
    $this->assertSession()->pageTextContains(Unicode::truncate($view['page[title]'], 32, FALSE, TRUE));
  }

}
