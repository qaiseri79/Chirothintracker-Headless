<?php

namespace Drupal\custom_module\Controller;

use Drupal\views\Views;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\contact\Entity\Message;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CustomRoute extends ControllerBase
{

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(MessengerInterface $messenger)
  {
    $this->messenger = $messenger;
  }


  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('messenger')
    );
  }

  public function CustomCannedMessage($nid, $patient_id)
  {
    $message = "";
    if ($nid) {
      $node = Node::load($nid);
      if ($node) {
        $subject = $node->getTitle();
        $field_message = $node->get("field_message")->getValue();
        if ($field_message) {
          $message = $field_message[0]["value"];
          $this->UnflagPatient($patient_id);
          $this->SendMessage($patient_id, $message);
          (new RedirectResponse('/summary'))->send();
          $this->messenger->addMessage($this->t('Message Sent successfully.'), 'status');
        }
      }
    }
    return new RedirectResponse('/summary');
  }

  public function MessageRequest()
  {
    $operation = \Drupal::request()->query->get('operation');
    $patient_id = \Drupal::request()->query->get('patient_id');
    $message_value = "";
    if ($operation == "default_review") {
      $message_value = "<p>Your submission has been reviewed. Make it a great day!</p>";
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);
    }

    if ($operation == "constipation") {
      $account = User::load(\Drupal::currentUser()->id());
      $clinic = $account->get("field_clinic")->getValue()[0]["target_id"];
      $storage = \Drupal::service('entity_type.manager')->getStorage('clinic');
      $clinic_entity = $storage->load($clinic);
      $brand = $clinic_entity->get('field_brand')->getValue()[0]["value"];
      $message_value = "<p>Constipation can be a real challenge with weight loss, and yet, regular bowel movements are essential to losing weight. Since you have indicated constipation as a flag, here is a list of things you can try.</p>";
      $message_value .= '<ul>
                <li>Start your day with a warm beverage</li>
                <li>Drink plenty of water throughout the day</li>
                <li>Get adequate sleep</li>
                <li>Drink a flushing tea before bed, like Yogi Smooth Move - ChiroKlenz - Senna – if you don’t have a bowel movement in the morning, drink another cup.</li>
                <li>Eat cherries, berries, pears, plums, apples or kiwi as your fruit component</li>
                <li>Eat broccoli, brussels sprouts, kale and spinach</li>
                <li>Move your body – gentle exercise</li>
                <li>Elevate your feet on a step stool (or squat) while sitting on the toilet</li>
                <li>Take a good digestive enzyme at the beginning of each meal</li>
                <li>Take a sugar free psyllium husk supplement, or a flushing type of supplement</li>
            </ul>';
      $message_value .= '<p>&nbsp;Below are some things that you should try to avoid:</p>';
      $message_value .= '<ul>
            <li>Sitting for long periods</li>
            <li>Skipping meals or any portion of your meal</li>
            <li>Stress</li>
            <li>Drinking too much caffeine</li>
        </ul>';

      if ($brand == "ChiroThin") {
        $message_value .= '<p>For help choosing the right supplement to help with constipation, please message us in the portal. The same company that supplies the ChiroThin drops have three great products to help with this issue: Nature Ease, CN Digestion and CN Flush, but there are plenty of other helpful products out there as well.</p>';

      } elseif ($brand != "ChiroThin") {
        $message_value .= '<p>For help choosing the right supplement to help with constipation, please message us in the portal.</p>';
      }
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);
    }

    if ($operation == "please_log_food") {
      $message_value = '<p>Good Morning!</p>';
      $message_value .= '<p>We have noticed that you have not logged your meal(s).&nbsp;Letting us know what you eat at every meal is very important to obtaining the best results on the program because the better the information you give us, the better supervision we can provide. &nbsp;It only takes a minute - You can do this!</p>';
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);

    }
    if ($operation == "skipped_meal") {
      $message_value = '<p>We’ve noticed that you have skipped portions of your meals. Please understand that consuming all three components of your meal plan is essential for your body to get what is necessary to prevent “Starvation Mode.”</p>';
      $message_value .= '<p>Starvation mode is a slowing of the metabolism in response to calorie restriction. The concept of starvation mode is a normal physiological process referred to as, “adaptive thermogenesis.” Fortunately, the program has been specifically designed to prevent adaptive thermogenesis, therefore producing superior results as compared to other weight loss programs.</p>';
      $message_value .= '<p>The nutrients from all components of your meal plan is essential to overcoming the resistance created by “Starvation Mode.” Please do your best to consume all the allowable fruit, vegetable and protein with each meal. Your weight loss success depends on it!</p>';
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);

    }
    if ($operation == "vary_food_choices") {
      $message_value = '<p>Your submission has been reviewed. We have noticed that your weight isn’t dropping as consistently as we expected. Sometimes when this occurs, changing up your meal choices consistently {especially the protein component)&nbsp;can be the difference between steady weight loss or stalling out. We want to see variability in your fruit and vegetable choices from day to day and from meal to meal, but a little more strict with your protein choices. We recommend that you change up your protein at each meal and don’t eat the same protein choice for at least 3 days. For example, if you eat chicken breast for lunch, have 93% lean ground beef for dinner. The next day, have nitrate free deli turkey meat for lunch and cod for dinner. The next day have lean pork loin for lunch and filet mignon for dinner. Then, you can go back to chicken breast on the 4<sup>th</sup> day. This will help your body get a wide variety of proteins and the amino acids it needs. If you have any questions, please don’t hesitate to send us a message. Get creative – and make it a terrific day!</p>';
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);
    }
    if ($operation == "inadequate_sleep") {
      $message_value = '<p><strong>A good night’s sleep supports immune system, neurological and hormonal function.</strong> It helps balance the microbial diversity in the gastrointestinal tract. Sleep is one of our most critical (and natural) forms of detoxification. Sleep is one of the biggest factors that we’ve found to be clinically relevant in weight loss resistance. If you’re not getting adequate sleep, chances are you’ll experience a few of these symptoms:</p>';
      $message_value .= '<ul>
                <li>detoxification challenges</li>
                <li>constipation</li>
                <li>impaired immune function</li>
                <li>hunger</li>
                <li>moodiness</li>
                <li>fatigue</li>
                <li>and more</li>
            </ul>';
      $message_value .= '<p><strong>Sleep issues can even induce addictive behaviors</strong> because, without proper rest, you’re more likely to look for substances to keep energized - like the wrong types of food.</p>';
      $message_value .= '<p>Please do your best to get 8 hours sleep each night because your health and weight loss depend on it. If you must use an alarm to wake up, this is an indicator that you need more sleep. If, no matter how hard you try, you’re unable to get ample sleep, reach out to us for assistance, but please understand the importance of adequate sleep for your weight loss and overall health goals.</p>';
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);

    }
    if ($operation == "no_changes_review") {
      $message_value = '<p>Your submission has been reviewed. There are no special changes to your program today. Continue (or go back to) the standard 4oz portion - two meal plan as prescribed. Make it wonderful day!</p>';
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);
    }
    if ($operation == "protein_day_today") {
      $message_value .= '<p><strong>Protein Day Instructions:</strong></p>';
      $message_value .= '<ul>
            <li>At Lunch Eat 6oz of protein&nbsp;<u>ONLY</u>. (measure raw).</li>
            <li>At Dinner, Eat 6oz of&nbsp;a <u>DIFFERENT </u>protein (measure raw).</li>
            <li>Try to include red meat for one of the meals.</li>
            <li>Do not consume any vegetables, fruits or the bread component. Some feel the need to consume a small amount of free vegetables along with the 6oz of protein, however we have found better results in those who just have the protein.&nbsp;</li></ul>';
      $message_value .= '<p>Remember water is a key component to consistent weight loss, so make sure that you are consuming between 100 oz and 120 oz of pure water every day, but today I would like you to increase your water intake to between 120 - 140oz.</p>';
      $message_value .= '<p>If you don’t drop tomorrow, I will send you further instructions. Make it a great day and remember adequate water intake is KEY!</p>';
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);
      $last_submission_view = Views::getView('last_submission_cs');
      $last_submission_view->setDisplay('block_1');
      $last_submission_view->setArguments([$patient_id]);
      $last_submission_view->execute();
      $last_submission_result = $last_submission_view->result;
      if ($last_submission_result) {
        $last_submission_id = $last_submission_result[0]->_entity->id();
        $contact_message = Message::load($last_submission_id);
        $flags = $contact_message->get("field_chiropractor_flags")->getValue();
        $flag_values = array_column($flags, 'target_id');
        if (!in_array(15, $flag_values)) {
          $flags[] = ['target_id' => 15];
          $contact_message->set('field_chiropractor_flags', $flags);
          $contact_message->save();
        }
      }
    }

    if ($operation == "protein_day_tomorrow") {
      $message_value = '<p>Upon reviewing your weight submissions for the previous 5 days, I am going to recommend that if <strong><u>tomorrow morning your weight does not drop below today’s weight</u></strong> that you do a protein day. A protein day will help your body go into a more aggressive fat burning process.&nbsp;</p>';
      $message_value .= '<p><strong>Protein Day Instructions:</strong></p>';
      $message_value .= '<ul>
            <li>At Lunch Eat 6oz of protein&nbsp;<u>ONLY</u>. (measure raw).</li>
            <li>At Dinner, Eat 6oz of&nbsp;a <u>DIFFERENT</u> protein (measure raw).</li>
            <li>Try to include red meat for one of the meals.</li>
            <li>Do not consume any vegetables, fruits or the bread component. Some feel the need to consume a small amount of free vegetables along with the 6oz of protein, however we have found better results in those who just have the protein.&nbsp;</li>
            <li>Remember water is a key component to consistent weight loss, so make sure that you are consuming between 100 oz and 120 oz of pure water every day, but today I would like you to increase your water intake to between 120 - 140oz.</li>
        </ul>';
      $message_value .= '<p>&nbsp;Get excited as we should expect to see some great numbers the following day!</p>';
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);
      $last_submission_view = Views::getView('last_submission_cs');
      $last_submission_view->setDisplay('block_1');
      $last_submission_view->setArguments([$patient_id]);
      $last_submission_view->execute();
      $last_submission_result = $last_submission_view->result;
      if ($last_submission_result) {
        $last_submission_id = $last_submission_result[0]->_entity->id();
        $contact_message = Message::load($last_submission_id);
        $flags = $contact_message->get("field_chiropractor_flags")->getValue();
        $flag_values = array_column($flags, 'target_id');
        if (!in_array(15, $flag_values)) {
          $flags[] = ['target_id' => 15];
          $contact_message->set('field_chiropractor_flags', $flags);
          $contact_message->save();
        }

      }
    }
    if ($operation == "apply_day_today") {
      $message_value = '<p>Good Morning! Upon reviewing your weight submissions for the previous 5 days, I am going to recommend that you do an apple day, <u>TODAY</u>. An apple day will help your body kick start the fat burning process.&nbsp;</p>';
      $message_value .= '<p><strong>Instructions:</strong></p>';
      $message_value .= '<ul>
            <li>You will need to have 7 apples on hand.</li>
            <li>Starting at 12pm, eat one apple every hour, on the hour until 6pm.</li>
            <li>Do not eat anything else to day.</li>
            <li>You do not need to worry about the variety or the size of the apple and you do not need to weigh them.</li>
            <li>Remember water is a key component to consistent weight loss, so make sure that you are consuming between 100 and 120 oz of pure water every day, but today I would like you to increase your water intake to between 120 - 140oz.</li>
        </ul>';
      $message_value .= '<p>Get excited as we should expect to see some great numbers following your apple day!</p>';
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);
      $last_submission_view = Views::getView('last_submission_cs');
      $last_submission_view->setDisplay('block_1');
      $last_submission_view->setArguments([$patient_id]);
      $last_submission_view->execute();
      $last_submission_result = $last_submission_view->result;
      if ($last_submission_result) {
        $last_submission_id = $last_submission_result[0]->_entity->id();
        $contact_message = Message::load($last_submission_id);
        $flags = $contact_message->get("field_chiropractor_flags")->getValue();
        $flag_values = array_column($flags, 'target_id');
        if (!in_array(18, $flag_values)) {
          $flags[] = ['target_id' => 18];
          $contact_message->set('field_chiropractor_flags', $flags);
          $contact_message->save();
        }

      }
    }
    if ($operation == "apply_day_tomorrow") {
      $message_value = '<p>Good Morning! Upon reviewing your weight submissions for the previous 5 days, I am going to recommend that if <u>tomorrow morning your weight does not drop below today’s weight</u> you do an apple day. An apple day will help your body kick start the fat burning process.&nbsp;</p>';
      $message_value .= '<p><strong>Instructions:</strong></p>';
      $message_value .= '<ul>
            <li>You will need to have 7 apples on hand.</li>
            <li>Starting at 12pm, eat one apple every hour, on the hour until 6pm.</li>
            <li>Do not eat anything else to day.</li>
            <li>You do not need to worry about the variety or the size of the apple and you do not need to weigh them.</li>
            <li>Remember water is a key component to consistent weight loss, so make sure that you are consuming between 100 and 120 oz of pure water every day, but today I would like you to increase your water intake to between 120 - 140oz.</li>
        </ul>';
      $message_value .= '<p>Get excited as we should expect to see some great numbers following your apple day!</p>';
      $message_value .= '<p>Happy crunching!</p>';
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);
      $last_submission_view = Views::getView('last_submission_cs');
      $last_submission_view->setDisplay('block_1');
      $last_submission_view->setArguments([$patient_id]);
      $last_submission_view->execute();
      $last_submission_result = $last_submission_view->result;
      if ($last_submission_result) {
        $last_submission_id = $last_submission_result[0]->_entity->id();
        $contact_message = Message::load($last_submission_id);
        $flags = $contact_message->get("field_chiropractor_flags")->getValue();
        $flag_values = array_column($flags, 'target_id');
        if (!in_array(18, $flag_values)) {
          $flags[] = ['target_id' => 18];
          $contact_message->set('field_chiropractor_flags', $flags);
          $contact_message->save();
        }

      }
    }
    if ($operation == "having_problems") {
      $message_value = 'I noticed you have hit a rough patch. Please read through these questions and respond to see if we can find out what is going on:';
      $message_value .= '* Are you drinking enough water? 80oz minimum but we recommend between 100 and 120 oz per day.';
      $message_value .= '* Are you dehydrated?';
      $message_value .= '* Have you tried adding electrolytes?';
      $message_value .= '* Are you eating enough food - all of your allowed food of each component?';
      $message_value .= '* Are you eating a variety of non free veggies and varying your proteins?';
      $message_value .= '* Have you missed your drops?';
      $message_value .= '* Are you constipated?';
      $message_value .= '* Are you eating free veggies?';
      $message_value .= '* Are you eating your meals within the 8 hour time frame?';
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);
    }
    if ($operation == "low_adherence") {
      $message_value = '<p>Your submission has been reviewed by your ChiroThin Team. We noticed a low adherence number. We want you to know that we understand it is sometimes difficult to resist “cheating.” Weight loss, however, is a game of momentum and each little cheat slows down your momentum and can cause it to come to a screeching halt! Remember to use the CRuSH Technique to help with Cravings, Stalls and Hunger. You can watch the video here: <a href="https://www.youtube.com/watch?v=r__2y99rJ0c">https://www.youtube.com/watch?v=r__2y99rJ0c</a>.&nbsp; Let’s re-commit and get back on track today day!</p>';
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);
    }
    if ($operation == "water_intake") {
      $message_value = '<p>Your submission has been reviewed. We have noticed your water intake is a little off. Just a reminder to keep your water intake between 100oz and 120oz each day. Your body needs water to flush out the toxins released from your fat cells. Keep up the good work and make it a great day!</p>';
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);
    }
    if ($operation == "proud_of_you") {
      $message_value = '<p>Your submission has been reviewed. We want you to know how proud we are of you and acknowledge you for how hard you are working toward reaching your goal. Hooray!</p>';
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);
    }
    if ($operation == "ask_for_referral") {
      $message_value = 'Your submission has been reviewed by your ChiroThin Team. You are doing so well and we are so encouraged by your results and your determination. When someone asks you how you are losing all that weight, please send them our way so we can help them, too! Keep up the great work and make it an amazing day!';
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);
    }
    if ($operation == "graduated") {
      $message_value = 'Congratulations! You have successfully completed the ChiroThin Weight Loss Program. We are now placing your account into archived status. We are always here for you and your health care needs.';
      $this->UnflagPatient($patient_id);
      $user = User::load($patient_id);
      $user->removeRole('enrolled_patient');
      $user->addRole('archived_patient');
      $user->changed->preserve = TRUE;
      $user->save();
      $this->SendMessage($patient_id, $message_value);
    }
    if ($operation == "we_miss_you") {
      $message_value = '<p>Hello, we noticed you have not logged in the tracker recently, we are hoping everything is alright. We understand sometimes life gets a little crazy, however, logging in daily is an essential piece of the program to keep you on track and help you reach your goals. Please let us know if you need anything, as we are here to help!</p>';
      $this->UnflagPatient($patient_id);
      $this->SendMessage($patient_id, $message_value);
    }
    $this->messenger->addMessage($this->t('Message Sent Successfully.'), 'status');
    return new RedirectResponse('/summary');
  }




  public function SendMessage($patient_id, $message_value)
  {
    $message = [
      'value' => $message_value,
      'format' => "filtered_html",
    ];
    // Create Message submission.
    $message = Message::create([
      'contact_form' => 'chiropractor_message',
      'uid' => \Drupal::currentUser()->id(),
      'field_message' => $message,
      'field_patient' => $patient_id,
    ]);
    $message->save();
  }

  public function UnflagPatient($patient_id)
  {
    $flag_service = \Drupal::service('flag');
    $flag = $flag_service->getFlagById('reviewed_patients');
    $chiropractor = User::load(\Drupal::currentUser()->id());
    $patient = User::load($patient_id);
    $flagging = $flag_service->getFlagging($flag, $patient, $chiropractor);
    if ($flagging) {
      $flag_service->unflag($flag, $patient, $chiropractor);
    }
  }

  public function RenderRecipeView()
  {
    return array();
  }
  public function RenderTrainingView()
  {
    return array();
  }
  public function resendWelcome()
  {
    $operation = \Drupal::request()->query->get('operation');
    $uid = \Drupal::request()->query->get('uid');
    if ($operation) {
      if ($operation == "resend_welcome_email") {
        $account = User::load($uid);
        $email = $account->getEmail();
        $field_full_name = $account->get("field_full_name")->getValue();
        if ($field_full_name) {
          $full_name = $field_full_name[0]["value"];
        }
        _user_mail_notify("register_admin_created", $account);
        (new RedirectResponse('/summary'))->send();
        // \Drupal::messenger()->addMessage("A welcome message has been sent to ".$full_name." (".$email.").", "status", TRUE);
        $this->messenger->addMessage($this->t("A welcome message has been sent to " . $full_name . " (" . $email . ")."), 'status');
      }
    }
    return new RedirectResponse('/summary');
  }

  public function ZeroDay()
  {
    $uid = \Drupal::request()->query->get('uid');
    $key = \Drupal::request()->query->get('key');
    if ($key === 'phase-0' && is_numeric($uid)) {
      $account = User::load($uid);
      $current_phase = $account->get('field_weight_loss_phase')->getValue()[0]['value'];
      $laser_patient_status = $account->get('field_laser_patient_status')->getValue()[0]['target_id'];
      if ($current_phase != "phase-0") {
        if ($laser_patient_status != "400") {
          $account->set("field_weight_loss_phase", "phase-0");
          $account->changed->preserve = TRUE;
          $account->save();
          (new RedirectResponse('/summary'))->send();
          $this->messenger->addMessage($this->t('The program status changed to "Zero-Day (Pre-Loading)" successfully.'), 'status');
        }
      }
    }
    return new RedirectResponse('/summary');
  }
  public function LoadingPhase()
  {
    $uid = \Drupal::request()->query->get('uid');
    $key = \Drupal::request()->query->get('key');
    if ($key === 'phase-1' && is_numeric($uid)) {
      $account = User::load($uid);
      $account->set("field_weight_loss_phase", "phase-1");
      $account->changed->preserve = TRUE;
      $account->save();
      (new RedirectResponse('/summary'))->send();
      $this->messenger->addMessage($this->t('The program status changed to "Loading" successfully.'), 'status');
    }
    return new RedirectResponse('/summary');
  }
  public function losingPhase()
  {
    $uid = \Drupal::request()->query->get('uid');
    $key = \Drupal::request()->query->get('key');
    if ($key === 'phase-2' && is_numeric($uid)) {
      $account = User::load($uid);
      $current_phase = $account->get('field_weight_loss_phase')->getValue()[0]['value'];
      $laser_patient_status = $account->get('field_laser_patient_status')->getValue()[0]['target_id'];
      if ($current_phase != "phase-2") {
        if ($laser_patient_status != "400") {
          $account->set("field_weight_loss_phase", "phase-2");
          $account->changed->preserve = TRUE;
          $account->save();
          (new RedirectResponse('/summary'))->send();
          $this->messenger->addMessage($this->t('The program status changed to "Losing Phase" successfully.'), 'status');
        }
      }
    }
    return new RedirectResponse('/summary');
  }
  public function cyclingPhase()
  {
    $uid = \Drupal::request()->query->get('uid');
    $key = \Drupal::request()->query->get('key');
    if ($key === 'phase-3' && is_numeric($uid)) {
      $account = User::load($uid);
      $current_phase = $account->get('field_weight_loss_phase')->getValue()[0]['value'];
      if ($current_phase != "phase-3") {
        $account->set("field_weight_loss_phase", "phase-3");
        $account->changed->preserve = TRUE;
        $account->save();
        $clinic = $account->get("field_clinic")->getValue();
        if ($clinic) {
          $clinic_id = $clinic[0]["target_id"];
          $storage = \Drupal::service('entity_type.manager')->getStorage('clinic');
          $clinic_entity = $storage->load($clinic_id);
          $field_brand = $clinic_entity->get('field_brand')->getValue()[0]['value'];
          if ($field_brand == "ChiroThin") {
            $message = '<h2>Cycling and Maintenance Phase</h2><br><p>Nice work! Your losing phase is now complete and so you will no longer be taking any drops. Congratulations! You are now ready to begin locking in your weight and your new basal metabolic rate!</p><br><p><strong>Please review the locking phase video on the website, under the <a href="https://www.howtochirothin.com">Training </a>tab.</strong></p><br><p>The purpose of the cycling and maintenance phase is to establish your new “metabolic set point” and that will help you stay at your new weight. It normally takes between 3 and 5 weeks to accomplish this goal but it is well worth the effort.</p><br><p>During the locking phase you will experience a fair number of fluctuations in your weight as your body finds its new set point. Try not to get upset with your scale if your weight goes up a pound or two. It will go back down - as long as you stick to protocol. Most people will still lose 2-4 pounds during this phase. This phase is just as important as the others, so don’t give up – keep up your self-discipline and finish strong!</p><br><p>From the standpoint of food choices, the cycling and maintenance phase is essentially the same as the losing phase. Do not add any foods to your diet that weren’t on the approved foods list, except for egg whites at breakfast. However, you will be eating greater quantities of food and adding breakfast as indicated below. Your body will adapt to its new Basal Metabolic Rate (BMR) and once that is accomplished, your new weight will be locked in.</p><br><p>On day 40 you will discontinue taking the drops. Then follow the same 4-4 and 4 pattern as on the losing phase for the next three days. On the fourth day, you will add a small breakfast of lean protein and fruit. Things like turkey bacon or 2 egg whites and free vegetables to make an omelet are great choices. You can finish your breakfast with about 4 oz of fruit.</p><br><p>Lunch and dinner are the same as in the losing phase, but women may have between 6-7 oz of each component and men between 7 and 8oz. At first it will seem like too much food – if you don’t want that much food, then only eat what you feel you need, especially in the first few days.</p><br><p>You will want to keep drinking your water, keep taking your AGGR each day as you lock in your new set point.</p><br><p>It is very important for you to continue to log your daily weights on the ChiroThinTracker website/mobile app. Your weight will be locked in when it is within 1/2 a pound for 7 consecutive days and we will be watching this very closely.</p><br><p>Finally, on day 43 you may initiate an Exercise Program as a part of a healthy lifestyle and to assist in making your weight loss permanent.</p><br>';
            // Create Message
            $message_value = [
              'value' => $message,
              'format' => "filtered_html"
            ];
            $current_time = new DrupalDateTime("now", "America/Chicago");
            $message = Message::create([
              'contact_form' => 'message',
              'uid' => $uid,
              'field_from' => \Drupal::currentUser()->id(),
              'field_to' => $uid,
              'field_message' => $message_value,
              'field_patient_uid' => $uid,
            ]);
            $message->save();
            $new_message_id = $message->id();
            $new_message = Message::load($new_message_id);
            $flag_service = \Drupal::service('flag');
            $flag = $flag_service->getFlagById('message_status_contact_storage');
            $account = User::load($new_message->get('uid')->getValue()[0]['target_id']);
            $flagging = $flag_service->getFlagging($flag, $new_message, $account);
            if (!$flagging) {
              $flag_service->flag($flag, $new_message, $account);
            }
            (new RedirectResponse('/summary'))->send();
            $this->messenger->addMessage($this->t('The program status changed to "Cycling/Maintenance Phase" successfully.'), 'status');
          }
        }
      }
    }
    return new RedirectResponse('/summary');
  }

  public function continuityPhase()
  {
    $uid = \Drupal::request()->query->get('uid');
    $key = \Drupal::request()->query->get('key');
    if ($key === 'phase-4' && is_numeric($uid)) {
      $account = User::load($uid);
      if ($account) {
        $account->set("field_weight_loss_phase", "phase-4");
        $account->changed->preserve = TRUE;
        $account->save();
        (new RedirectResponse('/summary'))->send();
        $this->messenger->addMessage($this->t('The program status changed to "Continuity Phase" successfully.'), 'status');
      }
    }
    return new RedirectResponse('/summary');
  }


}
