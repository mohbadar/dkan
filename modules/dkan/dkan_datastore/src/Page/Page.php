<?php

namespace Dkan\Datastore\Page;

use Dkan\Datastore\Resource;
use Dkan\Datastore\Manager\Factory;
use Dkan\Datastore\Manager\ManagerInterface;
use Dkan\Datastore\Page\Component\ManagerConfiguration;
use Dkan\Datastore\Page\Component\ManagerSelection;
use Dkan\Datastore\Page\Component\Status;

/**
 * Class Page.
 *
 * Generates the page that we use to manage the movement of resources
 * into the datastore.
 */
class Page {

  const BATCH_ITERATIONS = 1;
  const BATCH_TIME_LIMIT = 5;

  private $node;
  private $form;
  private $formState;

  /**
   * Constructor.
   */
  public function __construct($node, $form, &$form_state) {
    $this->node = $node;
    $this->form = $form;
    $this->formState = $form_state;
  }

  /**
   * Get the page/form.
   */
  public function get() {
    try {
      /* @var $resource \Dkan\Datastore\Resource */
      $resource = Resource::createFromDrupalNode($this->node);

      /* @var $manager ManagerInterface */
      $manager = (new Factory($resource))->get();

      // The drop button was pressed. Lets confirmed.
      if (isset($this->formState['storage']) && isset($this->formState['storage']['drop'])) {
        return $this->dropForm();
      }

      $html = "<p>Import the data from a CSV file into a database table to make it accessible through an API.</p>";
      $this->form['help'] = [
        '#type' => 'item',
        '#markup' => $html
      ];

      $html = (new Status($manager))->getHtml();
      $this->form['status'] = [
        '#type' => 'item',
        '#title' => t('Datastore Status'),
        '#markup' => "<dl>{$html}</dl>",
      ];

      $status = $manager->getStatus();
      if (in_array($status['data_import'], [ManagerInterface::DATA_IMPORT_READY, ManagerInterface::DATA_IMPORT_UNINITIALIZED])) {

        $this->form += (new ManagerSelection($resource, $manager))->getForm();

        $this->form += (new ManagerConfiguration($manager))->getForm();

        $this->form['actions'] = array('#type' => 'actions');
        $this->form['actions']['submit'] = array(
          '#type' => 'submit',
          '#value' => t("Import"),
        );
      }
      elseif (in_array($status['data_import'], [ManagerInterface::DATA_IMPORT_IN_PROGRESS, ManagerInterface::DATA_IMPORT_DONE])) {
        $this->form['actions']['drop'] = array(
          '#type' => 'submit',
          '#value' => t("Drop"),
          '#submit' => array('dkan_datastore_drop_submit'),
        );
      }

      return $this->form;
    }
    catch (\Exception $e) {
      drupal_set_message("The datastore does not support node {$this->node->nid}: {$e->getMessage()}");
      drupal_goto("/node/{$this->node->nid}");
    }
    return [];
  }

  /**
   * Form Submit.
   */
  public function submit() {
    $resource = Resource::createFromDrupalNode($this->node);

    /* @var $manager ManagerInterface */
    $manager = (new Factory($resource))->get();

    $values = $this->formState['values'];

    try {
      $value = isset($values['datastore_managers_selection']) ? $values['datastore_managers_selection'] : NULL;
      if (isset($value)) {
        (new ManagerSelection($resource, $manager))->submit($value);

        // The manager got configured we have to reload it.
        $manager = (new Factory($resource))->get();
      }

      $manager_values = [];
      foreach ($values as $property_name => $v) {
        if (substr_count($property_name, "datastore_manager_config") > 0) {
          $manager_values[$property_name] = $v;
        }
      }
      if (!empty($manager_values)) {
        (new ManagerConfiguration($manager))->submit($manager_values);
      }

      if ($values['submit'] == "Import") {
        $this->batchConfiguration($manager);
      }
      elseif ($values['submit'] == "Drop") {
        $this->dropFormSubmit($manager);
      }
    }
    catch (\Exception $e) {
      drupal_set_message($e->getMessage());
    }
  }

  /**
   * Batch event handler.
   */
  public function batchProcess($manager, &$context) {
    stream_wrapper_restore("https");
    stream_wrapper_restore("http");
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = 1;
    }
    /* @var $manager ManagerInterface */
    $manager->import();
    $context['sandbox']['progress']++;

    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Batch event handler.
   */
  public function batchFinished($success, $results, $operations) {
    drupal_set_message(t("Import finished"));
  }

  /**
   * Setting up the batch process for importing a file.
   */
  private function batchConfiguration(ManagerInterface $manager) {
    $manager->setImportTimelimit(self::BATCH_TIME_LIMIT);

    $batch = array(
      'operations' => [],
      'finished' => [$this, 'batchFinished'],
      'title' => t('Importing.'),
      'init_message' => t('Starting Import.'),
      'progress_message' => t('Processed @current out of @total.'),
      'error_message' => t('An error occurred during import.'),
    );

    for ($i = 0; $i < self::BATCH_ITERATIONS; $i++) {
      $batch['operations'][] = [[$this, 'batchProcess'], [$manager]];
    }

    batch_set($batch);
  }

  /**
   * Drop form.
   */
  private function dropForm() {
    $node = $this->form['#node'];

    $question = t('Are you sure you want to drop this datastore?');
    $path = 'node/' . $node->nid . '/datastore';
    $description = t('This operation will destroy the db table and all the data previously imported.');
    $yes = t('Drop');
    $no = t('Cancel');
    $name = 'drop';

    return confirm_form($this->form, $question, $path, $description, $yes, $no, $name);
  }

  /**
   * Form Submit.
   */
  private function dropFormSubmit(ManagerInterface $manager) {
    $manager->drop();
    $this->formState['redirect'] = "node/{$this->node->nid}/datastore";
    drupal_set_message(t("The datastore for %title has been successfully dropped.", ['%title' => $this->node->title]));
  }

}