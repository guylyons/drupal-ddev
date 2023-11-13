<?php

namespace Drupal\guy_test\Form;

use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\media_library\Form\FileUploadForm;

class RemoteUploadForm extends FileUploadForm {

  /**
   * The Form ID.
   */
  public function getFormId() {
    return $this->getBaseFormId() . '_remote_upload';
  }

  /**
   * Adds a URL field for uploading via a remote resource.
   *
   * @param array $form
   * @param FormStateInterface $form_state
   * @return $form
   */
  protected function buildInputElement(array $form, FormStateInterface $form_state) {

    $form = parent::buildInputElement($form, $form_state);

    $form['container']['remote_upload'] = [
      '#type' => 'url',
      '#title' => $this->t('Remote Source URL'),
      '#description' => $this->t('Enter the URL of the remote media source.'),
      '#required' => TRUE,
    ];
    $form['container']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload'),
      '#button_type' => 'primary',
      '#submit' => ['::remoteButtonSubmit'],
      '#ajax' => [
        'callback' => '::updateFormCallback',
        'wrapper' => 'media-library-wrapper',
        // Add a fixed URL to post the form since AJAX forms are automatically
        // posted to <current> instead of $form['#action'].
        // @todo Remove when https://www.drupal.org/project/drupal/issues/2504115
        //   is fixed.
        'url' => Url::fromRoute('media_library.ui'),
        'options' => [
          'query' => $this->getMediaLibraryState($form_state)->all() + [
            FormBuilderInterface::AJAX_FORM_REQUEST => TRUE,
          ],
        ],
      ],
    ];

    return $form;
  }

  /**
   * Submit handler for the Upload button.
   *
   * @param array $form
   *   The form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function remoteButtonSubmit(array $form, FormStateInterface $form_state) {
    $url = $form_state->getValue('remote_upload');
    if ($url) {
      $file_contents = file_get_contents($url);

      if ($file_contents !== false) {
        $file_system = \Drupal::service('file_system');
        $filepath = 'public://' . basename($url);

        if ($file_system->saveData($file_contents, $filepath, FileSystemInterface::EXISTS_REPLACE)) {
          $file = File::create([
            'uri' => $filepath,
          ]);
          $file->setPermanent();
          $file->save();
        }
        $this->processInputValues([$file], $form, $form_state);
      }
    }
  }

}
