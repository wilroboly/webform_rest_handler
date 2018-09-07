<?php

namespace Drupal\webform_rest_handler\Plugin\WebformHandler;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Serialization\PhpSerialize;
use Drupal\webform_rest_handler\Component\Serialization\Xml;
//use Drupal\webform_rest_handler\Library\Data;
//use Drupal\webform_rest_handler\Library\Utils;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\webform\Element\WebformMessage;
use Drupal\webform\Plugin\WebformElement\WebformManagedFileBase;
use Drupal\webform\Plugin\WebformElementManagerInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformMessageManagerInterface;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformTokenManagerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

/**
 * Webform submission REST handler.
 *
 * @WebformHandler(
 *   id = "rest_service",
 *   label = @Translation("Send to remote service"),
 *   category = @Translation("External"),
 *   description = @Translation("Posts webform submissions to a REST/API endpoint"),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 *   tokens = TRUE,
 * )
 */
class RestWebformHandler extends WebformHandlerBase {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The token manager.
   *
   * @var \Drupal\webform\WebformTokenManagerInterface
   */
  protected $tokenManager;

  /**
   * The webform message manager.
   *
   * @var \Drupal\webform\WebformMessageManagerInterface
   */
  protected $messageManager;

  /**
   * A webform element plugin manager.
   *
   * @var \Drupal\webform\Plugin\WebformElementManagerInterface
   */
  protected $elementManager;

  /**
   * List of unsupported webforem submission properties.
   *
   * The below properties will not being included in a remote post.
   *
   * @var array
   */
  protected $unsupportedProperties = [
    'metatag',
  ];

  protected $types = [
    'x-www-form-urlencoded' => [
      'label' => 'x-www-form-urlencoded',
      'accepted coding languages' => ['yaml','json','php','xml'],
    ],
    'json' => [
      'label' => 'JSON',
      'accepted coding languages' => ['yaml','json','php'],
    ],
    'xml' => [
      'label' => 'XML',
      'accepted coding languages' => ['yaml','json','php','xml'],
    ],
  ];
  /**
   * List of supported coding languages for the code data field
   *
   * @var array $coding_languages
   */
  protected $coding_languages = [
    'yaml' => [
      'label' => 'YAML',
      'type' => 'webform_codemirror',
      'mode' => 'yaml',
      'decode' => 'Yaml::decode',
      'help' => 'There are loads of fun things you can do with YAML scripting. So, do not hesitate to look up the standard and play around with it.',
    ],
//    'html' => [
//      'label' => 'HTML',
//      'type' => 'webform_codemirror',
//      'mode' => 'html',
//      'decode' => FALSE,
//    ],
//    'twig' => [
//      'label' => 'Twig',
//      'type' => 'webform_codemirror',
//      'mode' => 'twig',
//      'decode' => FALSE,
//    ],
    'xml' => [
      'label' => 'XML',
      'type' => 'textarea',
      'mode' => 'xml',
      'attached' => [
        'library' => [
          'webform_rest_handler/codemirror-xml',
        ],
      ],
      'attributes' => [
        'class' => [
          'js-webform-rest-codemirror',
          'webform-codemirror',
          'html',
        ],
        'data-webform-codemirror-mode' => [
          'text/html'
        ],
      ],
      'decode' => 'Drupal\webform_rest_handler\Component\Serialization\Xml::decode',
      'help' => 'When setting up some XML, you should consider adding in various details of the root node and the definition of the document. Use YAML variables in the Custom Guzzle Options like : xml_root_node_name, xml_format_output, xml_version, xml_encoding or xml_standalone. These will be added forcibly if not acquired from the xml document properties.',
    ],
//    'js' => [
//      'label' => 'Javascript',
//      'type' => 'textarea',
//      'mode' => 'javascript',
//      'decode' => FALSE,
//    ],
    'json' => [
      'label' => 'JSON',
      'type' => 'textarea',
      'mode' => 'json',
      'attached' => [
        'library' => [
          'webform_rest_handler/codemirror-xml',
        ],
      ],
      'attributes' => [
        'class' => [
          'js-webform-rest-codemirror',
          'webform-codemirror',
          'json',
        ],
        'data-webform-codemirror-mode' => [
          'application/json'
        ],
      ],
      'decode' => 'Json:decode',
      'help' => '',
    ],
    'php' => [
      'label' => 'PHP Serialized',
      'type' => 'textarea',
      'mode' => 'php',
      'attached' => [
        'library' => [
          'webform_rest_handler/codemirror-xml',
        ],
      ],
      'attributes' => [
        'class' => [
          'js-webform-rest-codemirror',
          'webform-codemirror',
          'php',
        ],
        'data-webform-codemirror-mode' => [
          'text/x-php'
        ],
      ],
      'decode' => 'PhpSerialize::decode',
      'help' => '',
    ],
  ];

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, WebformSubmissionConditionsValidatorInterface $conditions_validator, ModuleHandlerInterface $module_handler, ClientInterface $http_client, WebformTokenManagerInterface $token_manager, WebformMessageManagerInterface $message_manager, WebformElementManagerInterface $element_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger_factory, $config_factory, $entity_type_manager, $conditions_validator);
    $this->moduleHandler = $module_handler;
    $this->httpClient = $http_client;
    $this->tokenManager = $token_manager;
    $this->messageManager = $message_manager;
    $this->elementManager = $element_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('webform_submission.conditions_validator'),
      $container->get('module_handler'),
      $container->get('http_client'),
      $container->get('webform.token_manager'),
      $container->get('webform.message_manager'),
      $container->get('plugin.manager.webform.element')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    $configuration = $this->getConfiguration();
    $settings = $configuration['settings'];

    if (!$this->isResultsEnabled()) {
      $settings['updated_url'] = '';
      $settings['deleted_url'] = '';
    }
    if (!$this->isDraftEnabled()) {
      $settings['draft_url'] = '';
    }
    if (!$this->isConvertEnabled()) {
      $settings['converted_url'] = '';
    }
    if (!$this->isUnsavedEnabled()) {
      $settings['unsaved_url'] = '';
    }

    return [
        '#settings' => $settings,
      ] + parent::getSummary();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $field_names = array_keys(\Drupal::service('entity_field.manager')->getBaseFieldDefinitions('webform_submission'));
    $excluded_data = array_combine($field_names, $field_names);
    $defaults = [
      'method' => 'POST',
      'type' => 'x-www-form-urlencoded',
      'excluded_data' => $excluded_data,
      'body_data' => '',
      'body_data_mode' => 'yaml',
      'customize_options' => '',
      'debug' => FALSE,
      // States.
      'completed_url' => '',
      'completed_custom_data' => '',
      'updated_url' => '',
      'updated_custom_data' => '',
      'deleted_url' => '',
      'deleted_custom_data' => '',
      'draft_url' => '',
      'draft_custom_data' => '',
      'converted_url' => '',
      'converted_custom_data' => '',
      'unsaved_url' => '',
      'unsaved_custom_data' => '',
      // Custom response messages.
      'message' => '',
      'messages' => [],
    ];
    foreach ($this->coding_languages as $code => $values) {
      $defaults['body_data_' . $code] = '';
    }
    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $webform = $this->getWebform();

    // States.
    $states = [
      WebformSubmissionInterface::STATE_COMPLETED => [
        'state' => $this->t('completed'),
        'label' => $this->t('Completed'),
        'description' => $this->t('Post data when submission is <b>completed</b>.'),
        'access' => TRUE,
      ],
      WebformSubmissionInterface::STATE_UPDATED => [
        'state' => $this->t('updated'),
        'label' => $this->t('Updated'),
        'description' => $this->t('Post data when submission is <b>updated</b>.'),
        'access' => $this->isResultsEnabled(),
      ],
      WebformSubmissionInterface::STATE_DELETED => [
        'state' => $this->t('deleted'),
        'label' => $this->t('Deleted'),
        'description' => $this->t('Post data when submission is <b>deleted</b>.'),
        'access' => $this->isResultsEnabled(),
      ],
      WebformSubmissionInterface::STATE_DRAFT => [
        'state' => $this->t('draft'),
        'label' => $this->t('Draft'),
        'description' => $this->t('Post data when <b>draft</b> is saved.'),
        'access' => $this->isDraftEnabled(),
      ],
      WebformSubmissionInterface::STATE_CONVERTED => [
        'state' => $this->t('converted'),
        'label' => $this->t('Converted'),
        'description' => $this->t('Post data when anonymous submission is <b>converted</b> to authenticated.'),
        'access' => $this->isConvertEnabled(),
      ],
      WebformSubmissionInterface::STATE_UNSAVED => [
        'state' => $this->t('unsaved'),
        'label' => $this->t('Unsaved'),
        'description' => $this->t('Send data to REST when anonymous submission is <b>unsaved</b> for validation. Result will be <b>saved</b> once done.'),
        'access' => $this->isUnsavedEnabled(),
      ],
    ];

    $token_types = ['config_token', 'webform', 'webform_submission'];

    foreach ($states as $state => $state_item) {
      $state_url = $state . '_url';
      $state_custom_data = $state . '_custom_data';
      $t_args = [
        '@state' => $state_item['state'],
        '@title' => $state_item['label'],
        '@url' => 'http://mulesoft/api/v1/endpoint_' . $state . '_handler.php',
      ];
      $form[$state] = [
        '#type' => 'details',
        '#open' => ($state === (WebformSubmissionInterface::STATE_COMPLETED || WebformSubmissionInterface::STATE_UNSAVED)),
        '#title' => $state_item['label'],
        '#description' => $state_item['description'],
        '#access' => $state_item['access'],
      ];
      $form[$state][$state_url] = [
        '#type' => 'url',
        '#title' => $this->t('@title URL', $t_args),
        '#description' => $this->t('The full URI for a REST endpoint to post the webform submission once it is @state. (e.g. @url)', $t_args),
//        '#required' => ($state === WebformSubmissionInterface::STATE_COMPLETED),
        '#parents' => ['settings', $state_url],
        '#default_value' => $this->configuration[$state_url],
      ];
      $form[$state][$state_custom_data] = [
        '#type' => 'webform_codemirror',
        '#mode' => 'yaml',
        '#title' => $this->t('Modify @title data', $t_args),
        '#description' => $this->t('Use YAML to modify the data that will be included when a webform submission is @state.', $t_args),
        '#parents' => ['settings', $state_custom_data],
        '#states' => ['visible' => [':input[name="settings[' . $state_url . ']"]' => ['filled' => TRUE]]],
        '#default_value' => $this->configuration[$state_custom_data],
      ];
      if ($state === WebformSubmissionInterface::STATE_COMPLETED) {
        $form[$state]['token'] = [
          '#type' => 'webform_message',
          '#message_message' => $this->t('Response data can be passed to the submission data using [webform:handler:{machine_name}:{state}:{key}] tokens. (i.e. [webform:handler:remote_post:completed:confirmation_number])'),
          '#message_type' => 'info',
        ];
      }
      $form[$state]['token_tree_link'] = $this->tokenManager->buildTreeElement(
        $token_types,
        $this->t('Use [webform_submission:values:ELEMENT_KEY:raw] to get plain text values.')
      );
    }

    // Additional.
    $form['additional'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Additional settings'),
    ];
    $form['additional']['method'] = [
      '#type' => 'select',
      '#title' => $this->t('Method'),
      '#description' => $this->t('The <b>POST</b> and <b>PUT</b> request methods request that a web server accept the data enclosed in the body of the request message. It is often used when uploading a file or when submitting a completed webform. In contrast, the HTTP <b>GET</b> request method retrieves information from the server and <b>DELETE</b> sends a request to delete a record from the remote server.'),
      '#required' => TRUE,
      '#options' => [
        'GET' => 'GET',
        'POST' => 'POST',
        'PUT' => 'PUT',
        'DELETE' => 'DELETE',
      ],
      '#parents' => ['settings', 'method'],
      '#default_value' => $this->configuration['method'],
    ];

    foreach ($this->types as $type => $type_info) {
      $type_options[$type] = $this->t($type_info['label']);
    }

    $form['additional']['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Body formatting'),
      '#description' => $this->t('This setting will convert the output of the Body data into a format usable by the remote endpoint. Depending on the system you will be posting to, you might need to use x-www-form-urlencoded, this is the default format for HTML webforms. Other choices can be <a href="http://www.json.org/" target="_blank">JSON</a> format or <a href="https://www.xml.com/" target="_blank">XML</a> format which have far more restrictive validation. Use with care!'),
      '#options' => $type_options,
      '#parents' => ['settings', 'type'],
      '#states' => [
        'visible' => [
          [':input[name="settings[method]"]' => ['value' => 'POST']],
          'or',
          [':input[name="settings[method]"]' => ['value' => 'PUT']],
          'or',
          [':input[name="settings[method]"]' => ['value' => 'DELETE']],
          ],
        'required' => [
          [':input[name="settings[method]"]' => ['value' => 'POST']],
          'or',
          [':input[name="settings[method]"]' => ['value' => 'PUT']],
          'or',
          [':input[name="settings[method]"]' => ['value' => 'DELETE']]
        ],
      ],
      '#default_value' => $this->configuration['type'],
    ];

    foreach ($this->coding_languages as $coding_language => $properties) {
      $coding_languages_options[$coding_language] = $properties['label'];
    }

    $form['additional']['body_data_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Body data mode'),
      '#description' => $this->t('Select which mode the Body data <strong>codemirror</strong> should use and how it will be converted to match the body formatting.'),
      '#parents' => ['settings', 'body_data_mode'],
      '#required' => TRUE,
      '#options' => $coding_languages_options,
      '#default_value' => $this->configuration['body_data_mode'],
    ];

    foreach ($this->coding_languages as $code => $properties) {
      $form['additional']['body_data_' . $code] = [
        '#type' => $properties['type'],
        '#mode' => $properties['mode'],
        '#title' => $this->t('Data Code'),
        '#description' => $this->t('Enter @title code here', array('@title' => $properties['label'])) . $this->t('<p>' . $properties['help'] . '</p>'),
        '#parents' => ['settings','body_data_' . $code],
        '#default_value' => $this->configuration['body_data_' . $code],
        '#states' => [
          'visible' => [
            ':input[name="settings[body_data_mode]"]' => ['value' => $code]
          ]
        ],
      ];
      if (isset($properties['attached'])) {
        $form['additional']['body_data_' . $code]['#attached'] = $properties['attached'];
      }
      if (isset($properties['attributes'])) {
        $form['additional']['body_data_' . $code]['#attributes'] = $properties['attributes'];
      }
    }

    $form['additional']['customize_options'] = [
      '#type' => 'webform_codemirror',
      '#mode' => 'yaml',
      '#title' => $this->t('Customize Guzzle options'),
      '#description' => $this->t('Enter custom <a href=":href">request options</a> that will be used by the Guzzle HTTP client. Request options can included custom headers. Please provide this in YAML format.', [':href' => 'http://docs.guzzlephp.org/en/stable/request-options.html']),
      '#parents' => ['settings', 'customize_options'],
      '#default_value' => $this->configuration['customize_options'],
    ];
    $form['additional']['message'] = [
      '#type' => 'webform_html_editor',
      '#title' => $this->t('Custom error response message'),
      '#description' => $this->t('This message is displayed when the response status code is not 2xx'),
      '#parents' => ['settings', 'message'],
      '#default_value' => $this->configuration['message'],
    ];
    $form['additional']['messages_token'] = [
      '#type' => 'webform_message',
      '#message_message' => $this->t('Response data can be passed to response message using [webform:handler:{machine_name}:{key}] tokens. (i.e. [webform:handler:remote_post:message])'),
      '#message_type' => 'info',
    ];
    $form['additional']['messages'] = [
      '#type' => 'webform_multiple',
      '#title' => $this->t('Custom error response messages'),
      '#description' => $this->t('Enter custom response messages for specific status codes.') . '<br/>' . $this->t('Defaults to: %value', ['%value' => $this->messageManager->render(WebformMessageManagerInterface::SUBMISSION_EXCEPTION_MESSAGE)]),
      '#empty_items' => 0,
      '#add' => FALSE,
      '#element' => [
        'code' => [
          '#type' => 'webform_select_other',
          '#title' => $this->t('Response status code'),
          '#options' => [
            '400' => $this->t('400 Bad Request'),
            '401' => $this->t('401 Unauthorized'),
            '403' => $this->t('403 Forbidden'),
            '404' => $this->t('404 Not Found'),
            '500' => $this->t('500 Internal Server Error'),
            '502' => $this->t('502 Bad Gateway'),
            '503' => $this->t('503 Service Unavailable'),
            '504' => $this->t('504 Gateway Timeout'),
          ],
          '#other__type' => 'number',
          '#other__description' => t('<a href="https://en.wikipedia.org/wiki/List_of_HTTP_status_codes">List of HTTP status codes</a>.'),
        ],
        'message' => [
          '#type' => 'webform_html_editor',
          '#title' => $this->t('Response message'),
        ],
      ],
      '#parents' => ['settings', 'messages'],
      '#default_value' => $this->configuration['messages'],
    ];

    // Development.
    $form['development'] = [
      '#type' => 'details',
      '#title' => $this->t('Development settings'),
    ];
    $form['development']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debugging'),
      '#description' => $this->t('If checked, posted submissions will be displayed onscreen to all users.'),
      '#return_value' => TRUE,
      '#parents' => ['settings', 'debug'],
      '#default_value' => $this->configuration['debug'],
    ];

    // Submission data.
    $form['submission_data'] = [
      '#type' => 'details',
      '#title' => $this->t('Submission data'),
    ];
    // Display warning about file uploads.
    if ($this->getWebform()->hasManagedFile()) {
      $form['submission_data']['managed_file_message'] = [
        '#type' => 'webform_message',
        '#message_message' => $this->t('Upload files will include the file\'s id, name, uri, and data (<a href=":href">Base64</a> encode).', [':href' => 'https://en.wikipedia.org/wiki/Base64']),
        '#message_type' => 'warning',
        '#message_close' => TRUE,
        '#message_id' => 'webform_node.references',
        '#message_storage' => WebformMessage::STORAGE_SESSION,
      ];
    }
    $form['submission_data']['excluded_data'] = [
      '#type' => 'webform_excluded_columns',
      '#title' => $this->t('Posted data'),
      '#title_display' => 'invisible',
      '#webform_id' => $webform->id(),
      '#required' => TRUE,
      '#parents' => ['settings', 'excluded_data'],
      '#default_value' => $this->configuration['excluded_data'],
    ];

    $this->tokenManager->elementValidate($form, $token_types);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->applyFormStateToConfiguration($form_state);
    if ($this->configuration['method'] === 'GET') {
      $this->configuration['type'] = '';
    }

    // Cast debug.
    $this->configuration['debug'] = (bool) $this->configuration['debug'];
  }

  /**
   * The point of this validationForm is to check whether we have any REMOTE issues.
   * Should we have any complications, we need to be able to set the form_state
   */
  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $state = $webform_submission->getWebform()->getSetting('results_disabled') ? WebformSubmissionInterface::STATE_COMPLETED : $webform_submission->getState();

    if ($state == WebformSubmissionInterface::STATE_UNSAVED && !empty($this->configuration[$state . '_url'])) {
      $errors = $this->remotePost($state, $webform_submission);
      // Ensure error is of Guzzle type
      if (is_a($errors, '\GuzzleHttp\Psr7\Response')) {
        // Let's decode the error body from XML.
        // @TODO: This is dirty and likely will have to be setup in such a way
        //        that we check the actual format of the error body. Otherwise
        //        we could have false positives or an outright code exception.
        $xml_array = Xml::decode((string) $errors->getBody());
        $temporary = $form_state->getTemporary() + ['response' => $xml_array];
        $form_state->setTemporary($temporary);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $state = $webform_submission->getWebform()->getSetting('results_disabled') ? WebformSubmissionInterface::STATE_COMPLETED : $webform_submission->getState();
    $this->remotePost($state, $webform_submission);
  }

  /**
   * {@inheritdoc}
   */
  public function postDelete(WebformSubmissionInterface $webform_submission) {
    $this->remotePost(WebformSubmissionInterface::STATE_DELETED, $webform_submission);
  }

  /**
   * Execute a remote post.
   *
   * @param string $state
   *   The state of the webform submission.
   *   Either STATE_NEW, STATE_DRAFT, STATE_COMPLETED, STATE_UPDATED, or
   *   STATE_CONVERTED depending on the last save operation performed.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   */
  protected function remotePost($state, WebformSubmissionInterface $webform_submission) {
    if (empty($this->configuration[$state . '_url'])) {
      return;
    }

    $this->messageManager->setWebformSubmission($webform_submission);

    $request_url = $this->configuration[$state . '_url'];
    $request_method = (!empty($this->configuration['method'])) ? $this->configuration['method'] : 'POST';

    $request_type = ($request_method !== 'GET') ? $this->configuration['type'] : NULL;
    $request_type = ($this->configuration['type'] == 'x-www-form-urlencoded') ? 'form_params' : $request_type;

    // Get request options with tokens replaced.
    $request_options = (!empty($this->configuration['customize_options'])) ? Yaml::decode($this->configuration['customize_options']) : [];
    $request_options = $this->tokenManager->replace($request_options, $webform_submission);

    $token_options = [];
    $token_data = [];

    // Get replace token values.
    $request_url = $this->tokenManager->replace($request_url, $webform_submission, $token_data, $token_options);
    $data = $this->getRequestData($state, $webform_submission);
    try {
      switch ($request_method) {
        case 'GET':
          // Append data as query string to the request URL.
          $request_url = Url::fromUri($request_url, ['query' => $data])->toString();
          $response = $this->httpClient->get($request_url, $request_options);
          break;

        case 'POST':
          if ($request_type == 'xml') {
            $xml = Xml::encode($data, $this->xmlOptionsMapping($request_options));
            $request_options['body'] = $xml;

          } else {
            $request_options[$request_type] = $data;
          }
          $response = $this->httpClient->post($request_url, $request_options);
          break;

        case 'PUT':
          if ($request_type == 'xml') {
            $xml = Xml::encode($data, $this->xmlOptionsMapping($request_options));
            $request_options['body'] = $xml;

          } else {
            $request_options[$request_type] = $data;
          }
          $response = $this->httpClient->put($request_url, $request_options);
          break;

        case 'DELETE':
          $request_options[$request_type] = $data;
          $response = $this->httpClient->delete($request_url, $request_options);
          break;

      }
    }
    catch (RequestException $request_exception) {
      $response = $request_exception->getResponse();

      // Encode HTML entities to prevent broken markup from breaking the page.
      $message = $request_exception->getMessage();
      $message = nl2br(htmlentities($message));

      $this->handleError($state, $message, $request_url, $request_method, $request_type, $request_options, $response);
      return $response;
    }

    // Display submission exception if response code is not 2xx.
    $status_code = $response->getStatusCode();
    if ($status_code < 200 || $status_code >= 300) {
      $message = $this->t('Remote post request return @status_code status code.', ['@status_code' => $status_code]);
      $this->handleError($state, $message, $request_url, $request_method, $request_type, $request_options, $response);
      return $status_code;
    }

    // If debugging is enabled, display the request and response.
    $this->debug(t('Remote post successful!'), $state, $request_url, $request_method, $request_type, $request_options, $response, 'warning');

    // Replace [webform:handler] tokens in submission data.
    // Data structured for [webform:handler:remote_post:completed:key] tokens.
    $submission_data = $webform_submission->getData();
    $submission_has_token = (strpos(print_r($submission_data, TRUE), '[webform:handler:' . $this->getHandlerId() . ':') !== FALSE) ? TRUE : FALSE;
    if ($submission_has_token) {
      $response_data = $this->getResponseData($response);
      $token_data = ['webform_handler' => [$this->getHandlerId() => [$state => $response_data]]];
      $submission_data = $this->tokenManager->replace($submission_data, $webform_submission, $token_data);
      $webform_submission->setData($submission_data);
      // Resave changes to the submission data without invoking any hooks
      // or handlers.
      if ($this->isResultsEnabled()) {
        $webform_submission->resave();
      }
    }
  }

  /**
   * Get a webform submission's request data.
   *
   * @param string $state
   *   The state of the webform submission.
   *   Either STATE_NEW, STATE_DRAFT, STATE_COMPLETED, STATE_UPDATED, or
   *   STATE_CONVERTED depending on the last save operation performed.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   *
   * @return array
   *   A webform submission converted to an associative array.
   */
  protected function getRequestData($state, WebformSubmissionInterface $webform_submission) {
    // Get submission and elements data.
    $data = $webform_submission->toArray(TRUE);

    // Remove unsupported properties from data.
    // These are typically added by other module's like metatag.
    $unsupported_properties = array_combine($this->unsupportedProperties, $this->unsupportedProperties);
    $data = array_diff_key($data, $unsupported_properties);

    // Flatten data and prioritize the element data over the
    // webform submission data.
    $element_data = $data['data'];
    unset($data['data']);
    $data = $element_data + $data;

    // Excluded selected submission data.
    $data = array_diff_key($data, $this->configuration['excluded_data']);

    // Append uploaded file name, uri, and base64 data to data.
    $webform = $this->getWebform();
    foreach ($data as $element_key => $element_value) {
      if (empty($element_value)) {
        continue;
      }

      $element = $webform->getElement($element_key);
      if (!$element) {
        continue;
      }

      $element_plugin = $this->elementManager->getElementInstance($element);
      if (!($element_plugin instanceof WebformManagedFileBase)) {
        continue;
      }

      /** @var \Drupal\file\FileInterface $file */
      $file = File::load($element_value);
      if (!$file) {
        continue;
      }

      $data[$element_key . '__name'] = $file->getFilename();
      $data[$element_key . '__uri'] = $file->getFileUri();
      $data[$element_key . '__data'] = base64_encode(file_get_contents($file->getFileUri()));
    }

    $content = $this->configuration['body_data_' . $this->configuration['body_data_mode']];
    if (!empty($content)) {

      $mode = $this->configuration['body_data_mode'];

      $decoded_content = $this->decodedData($mode, $content);
      $data = $decoded_content + $data;
    }

    // Append state Custom State data.
    if (!empty($this->configuration[$state . '_custom_data'])) {
      $data = Yaml::decode($this->configuration[$state . '_custom_data']) + $data;
    }

    // Replace tokens.
    $data = $this->tokenManager->replace($data, $webform_submission);

    return $data;
  }

  /**
   * Get response data.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response returned by the remote server.
   *
   * @return array|string
   *   An array of data, parse from JSON, or a string.
   */
  protected function getResponseData(ResponseInterface $response) {
    $body = (string) $response->getBody();
    $data = Json::decode($body, TRUE);
    return (json_last_error() === JSON_ERROR_NONE) ? $data : $body;
  }

  /**
   * Get webform handler tokens from response data.
   *
   * @param mixed $data
   *   Response data.
   * @param array $parents
   *   Webform handler token parents.
   *
   * @return array
   *   A list of webform handler tokens.
   */
  protected function getResponseTokens($data, array $parents = []) {
    $tokens = [];
    if (is_array($data)) {
      foreach ($data as $key => $value) {
        $tokens = array_merge($tokens, $this->getResponseTokens($value, array_merge($parents, [$key])));
      }
    }
    else {
      $tokens[] = '[' . implode(':', $parents) . ']';
    }
    return $tokens;
  }

  /**
   * Determine if saving of results is enabled.
   *
   * @return bool
   *   TRUE if saving of results is enabled.
   */
  protected function isResultsEnabled() {
    return ($this->getWebform()->getSetting('results_disabled') === FALSE);
  }

  /**
   * Determine if saving of draft is enabled.
   *
   * @return bool
   *   TRUE if saving of draft is enabled.
   */
  protected function isDraftEnabled() {
    return $this->isResultsEnabled() && ($this->getWebform()->getSetting('draft') != WebformInterface::DRAFT_NONE);
  }

  /**
   * Determine if converting anoynmous submissions to authenticated is enabled.
   *
   * @return bool
   *   TRUE if converting anoynmous submissions to authenticated is enabled.
   */
  protected function isConvertEnabled() {
    return $this->isDraftEnabled() && ($this->getWebform()->getSetting('form_convert_anonymous') === TRUE);
  }

  /**
   * Determine if validation of unsaved submissions is enabled.
   *
   * @return bool
   *   TRUE if saving of results is enabled.
   */
  protected function isUnsavedEnabled() {
    return ($this->getWebform()->getSetting('results_disabled') === FALSE);
  }

  /**
   * @param $mode string value representing the language
   * @param $content string value of the code to be decoded or not
   *
   * @return mixed
   *    should return the RAW or decoded version of the content
   */
  protected function decodedData($mode, $content) {
    if ($this->coding_languages[$mode]['decode']) {
      list($class, $method) = explode('::', $this->coding_languages[$mode]['decode']);
      $content = call_user_func("$class::$method", $content);
    }
    return $content;
  }

  /**
   * @param $request_options array of options which may contain XML document
   *                         settings which should be extracted and unset.
   *
   * @return array xml document options
   */
  protected function xmlOptionsMapping(&$request_options) {
    $options = [];
    if (isset($request_options['xml_root_node_name'])) {
      $options['xml_root_node_name'] = $request_options['xml_root_node_name'];
      unset($request_options['xml_root_node_name']);
    }
    if (isset($request_options['xml_format_output'])) {
      $options['xml_format_output'] = $request_options['xml_format_output'];
      unset($request_options['xml_format_output']);
    }
    if (isset($request_options['xml_version'])) {
      $options['xml_version'] = $request_options['xml_version'];
      unset($request_options['xml_version']);
    }
    if (isset($request_options['xml_encoding'])) {
      $options['xml_encoding'] = $request_options['xml_encoding'];
      unset($request_options['xml_encoding']);
    }
    if (isset($request_options['xml_standalone'])) {
      $options['xml_standalone'] = $request_options['xml_standalone'];
      unset($request_options['xml_standalone']);
    }
    return $options;
  }


  protected function formattingToModeMapping($type, $mode) {



    return $mapping;
  }

  /****************************************************************************/
  // Debug and exception handlers.
  /****************************************************************************/
  // @TODO: Fix the debug output screen to work with more than simply YAML::encode
  /**
   * Display debugging information.
   *
   * @param string $message
   *   Message to be displayed.
   * @param string $state
   *   The state of the webform submission.
   *   Either STATE_NEW, STATE_DRAFT, STATE_COMPLETED, STATE_UPDATED, or
   *   STATE_CONVERTED depending on the last save operation performed.
   * @param string $request_url
   *   The remote URL the request is being posted to.
   * @param string $request_method
   *   The method of remote post.
   * @param string $request_type
   *   The type of remote post.
   * @param string $request_options
   *   The requests options including the submission data..
   * @param \Psr\Http\Message\ResponseInterface|null $response
   *   The response returned by the remote server.
   * @param string $type
   *   The type of message to be displayed to the end use.
   */
  protected function debug($message, $state, $request_url, $request_method, $request_type, $request_options, ResponseInterface $response = NULL, $type = 'warning') {
    if (empty($this->configuration['debug'])) {
      return;
    }

    $build = [
      '#type' => 'details',
      '#title' => $this->t('Debug: Remote post: @title [@state]', ['@title' => $this->label(), '@state' => $state]),
    ];

    // State.
    $build['state'] = [
      '#type' => 'item',
      '#title' => $this->t('Submission state/operation:'),
      '#markup' => $state,
      '#wrapper_attributes' => ['class' => ['container-inline'], 'style' => 'margin: 0'],
    ];

    // Request.
    $build['request'] = ['#markup' => '<hr />'];
    $build['request_url'] = [
      '#type' => 'item',
      '#title' => $this->t('Request URL'),
      '#markup' => $request_url,
      '#wrapper_attributes' => ['class' => ['container-inline'], 'style' => 'margin: 0'],
    ];
    $build['request_method'] = [
      '#type' => 'item',
      '#title' => $this->t('Request method'),
      '#markup' => $request_method,
      '#wrapper_attributes' => ['class' => ['container-inline'], 'style' => 'margin: 0'],
    ];
    $build['request_type'] = [
      '#type' => 'item',
      '#title' => $this->t('Request type'),
      '#markup' => $request_type,
      '#wrapper_attributes' => ['class' => ['container-inline'], 'style' => 'margin: 0'],
    ];
    $build['request_options'] = [
      '#type' => 'item',
      '#title' => $this->t('Request options'),
      '#wrapper_attributes' => ['style' => 'margin: 0'],
      'data' => [
        '#markup' => htmlspecialchars(Yaml::encode($request_options)),
        '#prefix' => '<pre>',
        '#suffix' => '</pre>',
      ],
    ];

    // Response.
    $build['response'] = ['#markup' => '<hr />'];
    if ($response) {
      $build['response_code'] = [
        '#type' => 'item',
        '#title' => $this->t('Response status code:'),
        '#markup' => $response->getStatusCode(),
        '#wrapper_attributes' => ['class' => ['container-inline'], 'style' => 'margin: 0'],
      ];
      $build['response_header'] = [
        '#type' => 'item',
        '#title' => $this->t('Response header:'),
        '#wrapper_attributes' => ['style' => 'margin: 0'],
        'data' => [
          '#markup' => htmlspecialchars(Yaml::encode($response->getHeaders())),
          '#prefix' => '<pre>',
          '#suffix' => '</pre>',
        ],
      ];
      $build['response_body'] = [
        '#type' => 'item',
        '#wrapper_attributes' => ['style' => 'margin: 0'],
        '#title' => $this->t('Response body:'),
        'data' => [
          '#markup' => htmlspecialchars($response->getBody()),
          '#prefix' => '<pre>',
          '#suffix' => '</pre>',
        ],
      ];
      $response_data = $this->getResponseData($response);
      if ($response_data) {
        $build['response_data'] = [
          '#type' => 'item',
          '#wrapper_attributes' => ['style' => 'margin: 0'],
          '#title' => $this->t('Response data:'),
          'data' => [
            '#markup' => Yaml::encode($response_data),
            '#prefix' => '<pre>',
            '#suffix' => '</pre>',
          ],
        ];

      }
      if ($tokens = $this->getResponseTokens($response_data, ['webform', 'handler', $this->getHandlerId(), $state])) {
        asort($tokens);
        $build['response_tokens'] = [
          '#type' => 'item',
          '#wrapper_attributes' => ['style' => 'margin: 0'],
          '#title' => $this->t('Response tokens:'),
          'description' => ['#markup' => $this->t('Below tokens can ONLY be used to insert response data into value and hidden elements.')],
          'data' => [
            '#markup' => implode(PHP_EOL, $tokens),
            '#prefix' => '<pre>',
            '#suffix' => '</pre>',
          ],
        ];
      }
    }
    else {
      $build['response_code'] = [
        '#markup' => t('No response. Please see the recent log messages.'),
        '#prefix' => '<p>',
        '#suffix' => '</p>',
      ];
    }

    // Message.
    $build['message'] = ['#markup' => '<hr />'];
    $build['message_message'] = [
      '#type' => 'item',
      '#wrapper_attributes' => ['style' => 'margin: 0'],
      '#title' => $this->t('Message:'),
      '#markup' => $message,
    ];

    $this->messenger()->addMessage(\Drupal::service('renderer')->renderPlain($build), $type);
  }

  /**
   * Handle error by logging and display debugging and/or exception message.
   *
   * @param string $state
   *   The state of the webform submission.
   *   Either STATE_NEW, STATE_DRAFT, STATE_COMPLETED, STATE_UPDATED, or
   *   STATE_CONVERTED depending on the last save operation performed.
   * @param string $message
   *   Message to be displayed.
   * @param string $request_url
   *   The remote URL the request is being posted to.
   * @param string $request_method
   *   The method of remote post.
   * @param string $request_type
   *   The type of remote post.
   * @param string $request_options
   *   The requests options including the submission data..
   * @param \Psr\Http\Message\ResponseInterface|null $response
   *   The response returned by the remote server.
   */
  protected function handleError($state, $message, $request_url, $request_method, $request_type, $request_options, $response) {
    // If debugging is enabled, display the error message on screen.
    $this->debug($message, $state, $request_url, $request_method, $request_type, $request_options, $response, 'error');

    // Log error message.
    $context = [
      '@form' => $this->getWebform()->label(),
      '@state' => $state,
      '@type' => $request_type,
      '@url' => $request_url,
      '@message' => $message,
      'link' => $this->getWebform()
        ->toLink($this->t('Edit'), 'handlers')
        ->toString(),
    ];
    $this->getLogger()
      ->error('@form webform remote @type post (@state) to @url failed. @message', $context);

    // Display custom or default exception message.
    if ($custom_response_message = $this->getCustomResponseMessage($response)) {
      $token_data = [
        'webform_handler' => [
          $this->getHandlerId() => $this->getResponseData($response),
        ],
      ];
      $build_message = [
        '#markup' => $this->tokenManager->replace($custom_response_message, $this->getWebform(), $token_data),
      ];
      $this->messenger()->addError(\Drupal::service('renderer')->renderPlain($build_message));
    }
    else {
      $this->messageManager->display(WebformMessageManagerInterface::SUBMISSION_EXCEPTION_MESSAGE, 'error');
    }
  }

  /**
   * Get custom custom response message.
   *
   * @param \Psr\Http\Message\ResponseInterface|null $response
   *   The response returned by the remote server.
   *
   * @return string
   *   A custom custom response message.
   */
  protected function getCustomResponseMessage($response) {
    if ($response instanceof ResponseInterface) {
      $status_code = $response->getStatusCode();
      foreach ($this->configuration['messages'] as $message_item) {
        if ($message_item['code'] == $status_code) {
          return $message_item['message'];
        }
      }
    }
    return (!empty($this->configuration['message'])) ? $this->configuration['message'] : '';
  }

}
