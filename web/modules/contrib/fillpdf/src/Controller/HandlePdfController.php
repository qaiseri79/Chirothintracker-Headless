<?php

namespace Drupal\fillpdf\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Url;
use Drupal\fillpdf\Entity\FillPdfForm;
use Drupal\fillpdf\FillPdfContextManagerInterface;
use Drupal\fillpdf\FillPdfFormInterface;
use Drupal\fillpdf\FillPdfLinkManipulatorInterface;
use Drupal\fillpdf\Plugin\FillPdfActionPluginManager;
use Drupal\fillpdf\Plugin\PdfBackendManager;
use Drupal\fillpdf\Service\BackendProxyInterface;
use Drupal\fillpdf\TokenResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Entry point for populating merging data into PDFs.
 */
class HandlePdfController extends ControllerBase {

  /**
   * The FillPDF link manipulator.
   *
   * @var \Drupal\fillpdf\FillPdfLinkManipulatorInterface
   */
  protected $linkManipulator;

  /**
   * The FillPDF context manager.
   *
   * @var \Drupal\fillpdf\FillPdfContextManagerInterface
   */
  protected $contextManager;

  /**
   * The FillPDF token resolver.
   *
   * @var \Drupal\fillpdf\TokenResolverInterface
   */
  protected $tokenResolver;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The FillPDF backend manager.
   *
   * @var \Drupal\fillpdf\Plugin\PdfBackendManager
   */
  protected $backendManager;

  /**
   * The FillPDF action manager.
   *
   * @var \Drupal\fillpdf\Plugin\FillPdfActionPluginManager
   */
  protected $actionManager;

  /**
   * The backend proxy.
   *
   * @var \Drupal\fillpdf\Service\BackendProxyInterface
   */
  protected $backendProxy;

  /**
   * Provides a StreamWrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a FillPdfBackendManager object.
   *
   * @param \Drupal\fillpdf\FillPdfLinkManipulatorInterface $link_manipulator
   *   The FillPDF link manipulator.
   * @param \Drupal\fillpdf\FillPdfContextManagerInterface $context_manager
   *   The FillPDF context manager.
   * @param \Drupal\fillpdf\TokenResolverInterface $token_resolver
   *   The FillPDF token resolver.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\fillpdf\Plugin\PdfBackendManager $backend_manager
   *   The FillPDF backend manager.
   * @param \Drupal\fillpdf\Plugin\FillPdfActionPluginManager $action_manager
   *   The FillPDF action manager.
   * @param \Drupal\fillpdf\Service\BackendProxyInterface $backend_proxy
   *   The backend proxy.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   *   Provides a StreamWrapper manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(
    FillPdfLinkManipulatorInterface $link_manipulator,
    FillPdfContextManagerInterface $context_manager,
    TokenResolverInterface $token_resolver,
    RequestStack $request_stack,
    PdfBackendManager $backend_manager,
    FillPdfActionPluginManager $action_manager,
    BackendProxyInterface $backend_proxy,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->linkManipulator = $link_manipulator;
    $this->contextManager = $context_manager;
    $this->tokenResolver = $token_resolver;
    $this->requestStack = $request_stack;
    $this->backendManager = $backend_manager;
    $this->actionManager = $action_manager;
    $this->backendProxy = $backend_proxy;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('fillpdf.link_manipulator'),
      $container->get('fillpdf.context_manager'),
      $container->get('fillpdf.token_resolver'),
      $container->get('request_stack'),
      $container->get('plugin.manager.fillpdf.pdf_backend'),
      $container->get('plugin.manager.fillpdf_action.processor'),
      $container->get('fillpdf.backend_proxy'),
      $container->get('stream_wrapper_manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * Trigger hook that allows modules to alter $context().
   *
   * Creates hook_fillpdf_populate_pdf_context_alter().
   *
   * @param array $context
   *   The FillPDF context to alter.
   */
  public function alterContext(array &$context): void {
    $this->moduleHandler()->alter('fillpdf_populate_pdf_context', $context);
  }

  /**
   * Populates PDF template from context.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The action plugin's response object.
   *
   * @throws \InvalidArgumentException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   *   If one of the passed arguments is missing or does not pass the
   *   validation.
   */
  public function populatePdf() {
    $context = $this->linkManipulator->parseRequest($this->requestStack->getCurrentRequest());

    // Trigger hook_fillpdf_populate_pdf_context_alter().
    $this->alterContext($context);

    $fillpdf_form = FillPdfForm::load($context['fid']);
    $entities = $this->contextManager->loadEntities($context);

    $populated_pdf = $this->backendProxy->merge($fillpdf_form, $entities, $context);

    if (empty($populated_pdf)) {
      $this->messenger()->addError($this->t('Merging the FillPDF Form failed.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Generate the filename of downloaded PDF from title of the PDF set in
    // admin/structure/fillpdf/%fid.
    $filename = $this->buildFilename($fillpdf_form->title->value, $entities);

    // @todo When Rules integration ported, emit an event or whatever.
    return $this->handlePopulatedPdf($fillpdf_form, $populated_pdf, $context, $filename, $entities);
  }

  /**
   * Return the token-replaced filename of a populated PDF file.
   *
   * Given the same context, this will return the same filename that would be
   * used in ::populatePdf().
   *
   * @param array $context
   *   The FillPDF context.
   *
   * @return string
   *   The filename.
   */
  public function getFilename(array $context): string {
    $fillpdf_form = FillPdfForm::load($context['fid']);
    $entities = $this->contextManager->loadEntities($context);

    return $this->buildFilename($fillpdf_form->title->value, $entities);
  }

  /**
   * Builds the filename of a populated PDF file.
   *
   * @param string $original
   *   The original filename without tokens being replaced.
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   An array of entities to be used for replacing tokens.
   *
   * @return string
   *   The token-replaced filename.
   */
  protected function buildFilename($original, array $entities) {
    if (!$original) {
      return 'untitled.pdf';
    }

    // Replace tokens *before* sanitization.
    $original = (string) $this->tokenResolver->replace($original, $entities, ['content' => 'text']);

    $output_name = str_replace(' ', '_', $original);
    $output_name = preg_replace('/\.pdf$/i', '', $output_name);
    $output_name = preg_replace('/[^a-zA-Z0-9_.-]+/', '', $output_name) . '.pdf';

    return $output_name;
  }

  /**
   * Figure out what to do with the PDF and do it.
   *
   * @param \Drupal\fillpdf\FillPdfFormInterface $fillpdf_form
   *   An object containing the loaded record from {fillpdf_forms}.
   * @param string $pdf_data
   *   A string containing the content of the merged PDF.
   * @param array $context
   *   The request context as returned by
   *   FillPdfLinkManipulatorInterface::parseLink().
   * @param string $filename
   *   Filename of the merged PDF.
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   An array of entities to be used for replacing tokens. These may be still
   *   needed for generating the destination path, if the file is saved.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The action plugin's response object.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   *
   * @see \Drupal\fillpdf\FillPdfLinkManipulatorInterface::parseLink()
   */
  protected function handlePopulatedPdf(FillPdfFormInterface $fillpdf_form, $pdf_data, array $context, $filename, array $entities) {
    $force_download = FALSE;
    if (!empty($context['force_download'])) {
      $force_download = TRUE;
    }

    // Determine the appropriate action for the PDF.
    $scheme = $fillpdf_form->getStorageScheme();
    $is_available = array_key_exists($scheme, $this->streamWrapperManager->getWrappers(StreamWrapperInterface::WRITE_VISIBLE));
    $is_allowed = in_array($scheme, $this->configFactory->get('fillpdf.settings')->get('allowed_schemes') ?: []);

    if (empty($scheme)) {
      $action_plugin_id = 'download';
    }
    elseif (!$is_available || !$is_allowed) {
      // @todo We don't need the ID once an admin_title is #required,
      // see https://www.drupal.org/project/fillpdf/issues/3040776.
      $label = $fillpdf_form->label() . " ({$fillpdf_form->id()})";
      $this->getLogger('fillpdf')->critical('Saving a generated PDF file in unavailable storage scheme %scheme failed.', [
        '%scheme' => "$scheme://",
      ]);
      if ($this->currentUser()->hasPermission('administer pdfs')) {
        $this->messenger()->addError($this->t('File storage scheme %scheme:// is unavailable, so a PDF file generated from FillPDF form @link could not be stored.', [
          '%scheme' => $scheme,
          '@link' => Link::fromTextAndUrl($label, $fillpdf_form->toUrl())->toString(),
        ]));
      }
      // Make sure the file is only sent to the browser.
      $action_plugin_id = 'download';
    }
    else {
      $redirect = !empty($fillpdf_form->destination_redirect->value);
      $action_plugin_id = $redirect ? 'redirect' : 'save';
    }

    $action_configuration = [
      'form' => $fillpdf_form,
      'context' => $context,
      'entities' => $entities,
      'data' => $pdf_data,
      'filename' => $filename,
    ];

    /** @var \Drupal\fillpdf\Plugin\FillPdfActionPluginInterface $fillpdf_action */
    $fillpdf_action = $this->actionManager->createInstance($action_plugin_id, $action_configuration);
    $response = $fillpdf_action->execute();

    // If we are forcing a download, then manually get a Response from
    // the download action and return that. Side effects of other plugins will
    // still happen, obviously.
    if ($force_download) {
      /** @var FillPdfDownloadAction $download_action */
      $download_action = $this->actionManager
        ->createInstance('download', $action_configuration);
      $response = $download_action
        ->execute();
    }

    return $response;
  }

}
