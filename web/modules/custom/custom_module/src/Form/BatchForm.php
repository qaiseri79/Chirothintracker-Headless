<?php

namespace Drupal\custom_module\Form;

use Drupal\custom_module\BatchServiceInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Batch\BatchBuilder;

/**
 * Defines batch form.
 */
class BatchForm extends FormBase
{

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
    protected $messenger;

    /**
     * The batch service.
     *
     * @var \Drupal\modulename\BatchServiceInterface
     */
    protected $batch;

    /**
     * Constructor.
     *
     * @param \Drupal\Core\Messenger\MessengerInterface $messenger
     *   The messenger.
     * @param \Drupal\modulename\BatchServiceInterface $batch
     *   THe batch service.
     */
    public function __construct(MessengerInterface $messenger, BatchServiceInterface $batch)
    {
        $this->messenger = $messenger;
        $this->batch = $batch;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('messenger'),
            $container->get('custom_module.batch'),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'test_batch_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $form['actions']['#type'] = 'actions';
        $form['descriptions'] = ['#markup' => '<p>This form will run batch processing.</p>'];
        $form['actions']['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Run batch process'),
            '#button_type' => 'primary',
        ];
        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {

         $batchSize = 10;
        // $this->batch->create();
         /** @var \Drupal\Core\Batch\BatchBuilder $batchBuilder */
        $batchBuilder = (new BatchBuilder())
            ->setTitle($this->t('Running node updates...'))
            ->setFinishCallback([self::class, 'finishProcess'])
            ->setInitMessage('The initialization message (optional)')
            ->setProgressMessage('Completed @current of @total. See other placeholders.');

           $nodes = array_fill(0, 13, 'test');
        $total = count($nodes);
        $itemsToProcess = [];
        $i = 0;
        // Create multiple batch operations based on the $batchSize.
        foreach ($nodes as $node) {
            $i++;
            $itemsToProcess[] = $node;
            if ($i == $total || !($i % $batchSize)) {
                $batchBuilder->addOperation([BatchService::class, 'process'], [
                    'batch' => [
                        'items' => $itemsToProcess,
                        'size' => $batchSize,
                        'total' => $total,
                    ],
                ]);
                $itemsToProcess = [];
            }
        }

        batch_set($batchBuilder->toArray());
        


    }

      /**
     * {@inheritdoc}
     */
    public static function process(array $batch, array &$context): void
    {
        // Process elements stored in the each batch (operation).
        foreach ($batch['items'] as $item) {
            $context['results'][] = $item;
            sleep(1);
        }
        // Message displayed above the progress bar or in the CLI.
        $processedItems = !empty($context['results']) ? count($context['results']) : $batch['size'];
        $context['message'] = 'Processed ' . $processedItems . '/' . $batch['total'];

        \Drupal::logger('custom_module')->info(
            'Batch processing completed: ' . $processedItems . '/' . $batch['total']
        );
    }


       /**
     * {@inheritdoc}
     */
    public static function finishProcess($success, $results, array $operations): void
    {
        // Do something when processing is finished.
        if ($success) {
            \Drupal::logger('custom_module')->info('Batch processing completed.');
        }
        if (!empty($operations)) {
            \Drupal::logger('custom_module')->error('Batch processing failed: ' . implode(', ', $operations));
        }
    }
}