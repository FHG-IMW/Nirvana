<?php

namespace humhub\modules\cards\behaviors;

use humhub\modules\calendar\models\CalendarEntry;
use humhub\modules\cards\controllers\InviteController;
use humhub\modules\cards\models\Card;
use humhub\modules\cards\models\Cards;
use humhub\modules\cards\models\CardContent;
use humhub\modules\cards\models\SpaceUserRole;
use humhub\modules\cards\models\Step;
use humhub\modules\cards\models\StepUserSpace;
use humhub\modules\cards\models\UserCard;
use humhub\modules\cards\models\UserRoleWorkflow;
use humhub\modules\cards\models\WorkflowSpaceType;
use humhub\modules\space\models\Space;
use humhub\modules\user\models\GroupUser;
use humhub\modules\user\models\User;
use humhub\modules\user\models\Invite;
use humhub\modules\loginUsers\models\UserInviteGroup;

use Yii;
use yii\base\Behavior;
use yii\db\ActiveRecord;

class StepFlow extends Behavior
{

    protected $pendingStep;

    public function __construct()
    {
        $this->pendingStep = function ($step) {
            return ($step->status == StepUserSpace::STATUS_PENDING) ? true : '';
        };
    }

    public function events()
    {

        return [
            CalendarEntry::EVENT_AFTER_INSERT => 'beforeValidate',
        ];
//        return parent::events(); // TODO: Change the autogenerated stub
    }

    /**
     * Shows the questions tab
     * @param $card_id
     * @param $status
     * @param int $user_id
     * @param $space_id
     * @return bool
     */

    public static function updateFlowStatus($card_id, $status, $user_id = 0)
    {

        if (!$user_id) $user_id = Yii::$app->user->getId();

        $card = Card::findOne($card_id);

        if (!$card) return false;


        if($card->getStatus()->card_status != $status)
        {
            $transaction = Yii::$app->db->beginTransaction();
            try {
                if ($card->getChilds()) {

                    StepFlow::ParentCardStatusChange($card, $status, $user_id);


                } else {
                    StepFlow::SingleCardStatusChange($card, $status, $user_id);
                }
                $transaction->commit();
            }
            catch (Exception $e) {
                    if(isset($transaction)){
                        $transaction->rollBack();
                    }
                    Yii::error($e->getMessage());
                    return false;
                }
        }



    }
    private static function SingleCardStatusChange($card,$status,$user_id){
        $currentStatus=$card->getStatus()->card_status;
        $space_id = $card->space_id;
        $parentCard = $card->getParentCard();

        $valid=false;

        if($currentStatus==UserCard::STATUS_HOLD && $status==UserCard::STATUS_PENDING){
           StepFlow::saveCardStatus($card->id,$status,$user_id);

        }else if($currentStatus==UserCard::STATUS_PENDING && $status==UserCard::STATUS_DISMISSED){ //&& !$card->getCard()->one()->card_mandatory){
            StepFlow::saveCardStatus($card->id,$status,$user_id);
            StepFlow::UpdateRelatedCards($card);
            if ($parentCard) {
                $childsdismissorcompleted = Card::find()
                    ->where(['space_id' => $space_id])
                    ->leftJoin('cards', 'card.card_id = cards.id')
                    ->andWhere(['card_parent_id' => $parentCard->getCard()->one()->id])
                    ->leftJoin('user_card', 'user_card.card_id = card.id')
                    ->where(['user_id' => $user_id])
                    ->where("( card_status = '".UserCard::STATUS_DISMISSED."' OR card_status = '".UserCard::STATUS_COMPLETED."' )")
                    ->count();
                $childsdismiss = Card::find()
                    ->where(['space_id' => $space_id])
                    ->leftJoin('cards', 'card.card_id = cards.id')
                    ->andWhere(['card_parent_id' => $parentCard->getCard()->one()->id])
                    ->leftJoin('user_card', 'user_card.card_id = card.id')
                    ->where(['user_id' => $user_id, 'card_status' => UserCard::STATUS_DISMISSED])
                    ->count();
                $allchild = Card::find()
                    ->where(['space_id' => $space_id])
                    ->leftJoin('cards', 'card.card_id = cards.id')
                    ->where(['card_parent_id' => $parentCard->getCard()->one()->id])
                    ->count();
                if($childsdismiss==$allchild){
                    StepFlow::saveCardStatus($parentCard->id, UserCard::STATUS_DISMISSED,$user_id);
                    StepFlow:: UpdateRelatedCards($parentCard);
                }else if($childsdismissorcompleted==$allchild){
                    StepFlow::saveCardStatus($parentCard->id, UserCard::STATUS_COMPLETED,$user_id);
                }else if($parentCard->getStatus()->card_status != UserCard::STATUS_ONGOING){
                    StepFlow::saveCardStatus($parentCard->id, UserCard::STATUS_ONGOING,$user_id);
                }
                }



            StepFlow::UpdateWorkflowStep($space_id,$user_id);


        }else if($currentStatus==UserCard::STATUS_PENDING && $status==UserCard::STATUS_COMPLETED){
            StepFlow::saveCardStatus($card,$status,$user_id);

            if ($parentCard) {
                $childsdismissorcompleted = Card::find()
                    ->where(['space_id' => $space_id])
                    ->leftJoin('cards', 'card.card_id = cards.id')
                    ->andWhere(['card_parent_id' => $parentCard->getCard()->one()->id])
                    ->leftJoin('user_card', 'user_card.card_id = card.id')
                    ->where(['user_id' => $user_id])
                    ->where("( card_status = '".UserCard::STATUS_DISMISSED."' OR card_status = '".UserCard::STATUS_COMPLETED."' )")
                    ->count();
                $allchild = Card::find()
                    ->where(['space_id' => $space_id])
                    ->leftJoin('cards', 'card.card_id = cards.id')
                    ->where(['card_parent_id' => $parentCard->getCard()->one()->id])
                    ->count();
                if($childsdismissorcompleted==$allchild){
                    StepFlow::saveCardStatus($parentCard->id, UserCard::STATUS_COMPLETED,$user_id);
                }else if($parentCard->getStatus()->card_status != UserCard::STATUS_ONGOING){
                    StepFlow::saveCardStatus($parentCard->id, UserCard::STATUS_ONGOING,$user_id);
                }
            }
            StepFlow::UpdateWorkflowStep($space_id,$user_id);

        }else if($currentStatus==UserCard::STATUS_PENDING && $status==UserCard::STATUS_ONGOING){

            StepFlow::saveCardStatus($card,UserCard::STATUS_ONGOING,$user_id);
            if ($parentCard && $parentCard->getStatus()->card_status != UserCard::STATUS_ONGOING) {
                StepFlow::saveCardStatus($parentCard->id, UserCard::STATUS_ONGOING,$user_id);
            }
        }else if($currentStatus==UserCard::STATUS_COMPLETED && $status==UserCard::STATUS_ONGOING){

            StepFlow::saveCardStatus($card,UserCard::STATUS_ONGOING,$user_id);
            if ($parentCard && $parentCard->getStatus()->card_status != UserCard::STATUS_ONGOING) {
                StepFlow::saveCardStatus($parentCard->id, UserCard::STATUS_ONGOING,$user_id);
            }
        }else if($currentStatus==UserCard::STATUS_ONGOING && $status==UserCard::STATUS_COMPLETED){

            StepFlow::saveCardStatus($card,UserCard::STATUS_COMPLETED,$user_id);
            if ($parentCard) {
                $childsdismissorcompleted = Card::find()
                    ->where(['space_id' => $space_id])
                    ->leftJoin('cards', 'card.card_id = cards.id')
                    ->andWhere(['card_parent_id' => $parentCard->getCard()->one()->id])
                    ->leftJoin('user_card', 'user_card.card_id = card.id')
                    ->where(['user_id' => $user_id])
                    ->where("( card_status = '".UserCard::STATUS_DISMISSED."' OR card_status = '".UserCard::STATUS_COMPLETED."' )")
                    ->count();
                $allchild = Card::find()
                    ->where(['space_id' => $space_id])
                    ->leftJoin('cards', 'card.card_id = cards.id')
                    ->where(['card_parent_id' => $parentCard->getCard()->one()->id])
                    ->count();
                if($childsdismissorcompleted==$allchild){
                    StepFlow::saveCardStatus($parentCard->id, UserCard::STATUS_COMPLETED,$user_id);
                }else if($parentCard->getStatus()->card_status != UserCard::STATUS_ONGOING){
                    StepFlow::saveCardStatus($parentCard->id, UserCard::STATUS_ONGOING,$user_id);
                }
            }
            StepFlow::UpdateWorkflowStep($space_id,$user_id);
        }

        return $valid;
    }

    private static function UpdateRelatedCards($card)
    {

        $cards_related = $card->getRelatedCards();

        foreach ($cards_related as $card_related) {

            $user_cards_step = UserCard::findOne(array('card_id' => $card_related->id,
                'card_status' => 'pending' ));
            if (!$user_cards_step) continue;
            StepFlow::updateFlowStatus($card_related->id, UserCard::STATUS_DISMISSED,
                $user_cards_step->user_id);
        }

    }

    private static function ParentCardStatusChange($card,$status,$user_id)
    {
        $currentStatus = $card->getStatus()->card_status;
        $space_id = $card->space_id;

        if ($currentStatus == UserCard::STATUS_HOLD && $status == UserCard::STATUS_PENDING) {
            StepFlow::saveCardStatus($card->id, UserCard::STATUS_PENDING, $user_id);
        } else {
            if ($currentStatus == UserCard::STATUS_PENDING
                && $status == UserCard::STATUS_DISMISSED
            ) {
                $mandatory = Card::find()->leftJoin('cards', 'card.card_id=cards.id')
                    ->where(array('space_id' => $space_id, 'card_mandatory' => 1,
                        'card_parent_id' => $card->getCard()->one()->id))->count();
                if ($mandatory == 0) {
                    StepFlow::saveCardStatus($card->id, $status, $user_id);
                    StepFlow:: UpdateRelatedCards($card);
                    foreach ($card->getChilds() as $child) {

                        StepFlow::saveCardStatus($child->id, UserCard::STATUS_DISMISSED, $user_id);
                        StepFlow::UpdateRelatedCards($child);
                    }
                }

                StepFlow::UpdateWorkflowStep($space_id, $user_id);
            } else {
                if ($currentStatus == UserCard::STATUS_ONGOING
                    && $status == UserCard::STATUS_COMPLETED
                ) {

                    $childsdismissorcompleted = Card::find()
                        ->where(['space_id' => $space_id])
                        ->leftJoin('cards', 'card.card_id = cards.id')
                        ->andWhere(['card_parent_id' => $card->getCard()->one()->id])
                        ->leftJoin('user_card', 'user_card.card_id = card.id')
                        ->where(['user_id' => $user_id])
                        ->where("( card_status = '" . UserCard::STATUS_DISMISSED
                            . "' OR card_status = '" . UserCard::STATUS_COMPLETED . "' )")
                        ->count();
                    $allchild = Card::find()
                        ->where(['space_id' => $space_id])
                        ->leftJoin('cards', 'card.card_id = cards.id')
                        ->where(['card_parent_id' => $card->getCard()->one()->id])
                        ->count();
                    if ($childsdismissorcompleted == $allchild) {
                        StepFlow::saveCardStatus($card->id, UserCard::STATUS_COMPLETED, $user_id);
                    }

                    StepFlow::UpdateWorkflowStep($space_id, $user_id);
                }
            }
        }

    }

    private static function saveCardStatus($card_id,$status,$user_id){
        $card = UserCard::findOne(array('user_id' => $user_id, 'card_id' => $card_id));
        $card->card_status = $status;
        $card->save();
    }

    private static function UpdateWorkflowStep($space_id,$user_id){

        //get workflow step
        $current_user_step = StepUserSpace::findOne(array('user_id' => $user_id,
            'space_id' => $space_id, 'status' => 'pending'));


        if (!$current_user_step) { //workflow not started. there are not steps with pending status. select the first hold status

            $current_user_step = StepUserSpace::findOne(array('user_id' => $user_id,
                'space_id' => $space_id, 'status' => 'hold'));

        }
        $cards = Card::find()->leftJoin('cards',
            'card.card_id=cards.id')->leftJoin('user_card',
            'user_card.card_id = card.id')->where("card.space_id = :space_id and cards.card_parent_id is null and cards.step_id = :step_id
            and (card_status = 'pending' OR card_status = 'ongoing')",
            array('space_id' => $space_id,
                'step_id' => $current_user_step->step_id))
            ->count();
        //if (!$cards) return;

        //if there are not pending or ongoing steps
        if ($cards == 0) {

            //update the current state as completed
            $current_user_step->status = StepUserSpace::STATUS_COMPLETED;
            $current_user_step->save();

            //update the next step status as pending
            $current_step = Step::findOne($current_user_step->step_id);

            $next_step = Step::findOne(array('workflow_id' => $current_step->workflow_id,
                'user_role_id' => $current_step->user_role_id,
                'step_order' => $current_step->step_order + 1));

            if ($next_step) {
                $next_user_step = StepUserSpace::findOne(array('user_id' => $user_id,
                    'space_id' => $space_id, 'step_id' => $next_step->id));
                $next_user_step->status = 'pending';
                $next_user_step->save();
            }
        }
    }

    public function infoUserSteps( $space_id, $user_id = 0 )
    {

        if (!$user_id) $user_id = Yii::$app->user->getId();

        $query = StepUserSpace::find();
        $query->joinWith('step', true, 'INNER JOIN');
        $query->joinWith('space', true, 'INNER JOIN');
        $query->where(['step_user_space.user_id' => $user_id, 'space.id' => $space_id]);
        $steps = $query->all();


        return (object) ['totalSteps' => $steps, 'hasStep' =>
            (count(array_filter(array_map($this->pendingStep, $steps), 'strlen')) > 0)];
    }

    public function enableNextUserStep($space_id, $user_id = 0)
    {

        if (!$user_id) $user_id = Yii::$app->user->getId();

        $query = StepUserSpace::find();
        $query->leftJoin('step', 'step_user_space.step_id=step.id');
        $query->where([
            'step_user_space.user_id'   => $user_id,
            'step_user_space.space_id'  => $space_id,
            'step_user_space.status' => StepUserSpace::STATUS_HOLD
        ]);

        $query->orderBy('step.step_order ASC');

        $next_user_step = $query->one();

        if ($next_user_step) {
            $before_completed = false;

            //Comprobar que el paso anterior esté completado o que sea el primero.

            if ($next_user_step->getStep()->one()->step_order == 0) $before_completed = true;

            if (!$before_completed) {
                $next_step = Step::findOne($next_user_step->step_id);

                $previous_step = Step::findOne(array('workflow_id' => $next_step->workflow_id,
                    'user_role_id' => $next_step->user_role_id,
                    'step_order' => $next_step->step_order - 1 ));

                $previous_user_step = StepUserSpace::findOne(array('user_id' => $user_id,
                    'space_id' => $space_id, 'step_id' => $previous_step->id));

                $before_completed = ($previous_user_step) ? ($previous_user_step->status != StepUserSpace::STATUS_PENDING) : true;

            }

            if ($before_completed) {
                $next_user_step->status = StepUserSpace::STATUS_PENDING;
                $next_user_step->save();
            }


        }


    }
    
    public static function insertCardContent($card_id, $content_id, $class, $order) {
        $newContentRelated = new CardContent();
        $newContentRelated->card_id = $card_id;
        $newContentRelated->content_related_id = $content_id;
        $newContentRelated->tag = $class;
        $newContentRelated->order = $order;
        $newContentRelated->save();
    }

    public static function CardContentRelated($card_id, $content_id, $class, $order = 0) {

		$card = Card::findOne($card_id);

		if (!$card) return;

        $cards_related = $card->getRelatedCards();

        $card_child_relate = $card->getChildRelated();

        StepFlow::insertCardContent($card_id, $content_id, $class, $order);

        foreach ($cards_related as $card_related) {
			StepFlow::insertCardContent($card_related->id, $content_id, $class, $order);
        }
        
        foreach ($card_child_relate as $card_related) {
			StepFlow::insertCardContent($card_related->id, $content_id, $class, $order);
        }
    }

    public static function CardContentRelatedOnly($card_id, $content_id, $class, $order = 0) {

        $card = Card::findOne($card_id);

        if (!$card) return;

        $cards_related = $card->getRelatedCards();

        $card_child_relate = $card->getChildRelated();

        foreach ($cards_related as $card_related) {
            StepFlow::insertCardContent($card_related->id, $content_id, $class, $order);
        }

        foreach ($card_child_relate as $card_related) {
            StepFlow::insertCardContent($card_related->id, $content_id, $class, $order);
        }
    }

    public static function updateCardContentStatus ($content_id, $space_id, $user_id = 0) {

        if (!$user_id) $user_id = Yii::$app->user->getId();

        $user_cards = UserCard::find()
            ->leftJoin('card',          'user_card.card_id      = card.id')
            ->leftJoin('card_content',  'card_content.card_id   = card.id')
            ->where('card_content.content_related_id = :content_id
            AND card.space_id = :space_id AND user_card.user_id = :user_id',
                array('content_id' => $content_id, 'space_id' => $space_id,
                    'user_id' => $user_id))->all();

        if (!$user_cards) return;

        foreach ($user_cards as $user_card) {
            StepFlow::updateFlowStatus($user_card->card_id, StepUserSpace::STATUS_COMPLETED);
        }

    }

    /**
     * @param $space
     * @return WorkflowSpaceType | null
     */
    public static function currentWorkflow($space) {

//        $steps = WorkflowSpaceType::findAll(array('group_id' => Yii::$app->user->getGroups()->one()->id));

        $workflow = WorkflowSpaceType::findOne(array('space_type_id' => $space->space_type_id));

        return $workflow;

    }

    public static function setStepUser($object, $steps) {

        foreach ($steps as $step) {

            $s_u_s = new StepUserSpace();
            $s_u_s->step_id  = $step->id;
            $s_u_s->user_id  = $object->user_id;
            $s_u_s->space_id = $object->space_id;
            $s_u_s->status   =  StepUserSpace::STATUS_HOLD;
            $s_u_s->save();

            $cards = Card::find()->leftJoin('cards',
                'card.card_id=cards.id')->where(array('card.space_id' => $object->space_id,
                'cards.step_id' => $step->id))->all();

            foreach ($cards as $c_) {
                $u_c = new UserCard();
                $u_c->user_id       = $object->user_id;
                $u_c->card_id       = $c_->id;
                $u_c->card_status   = "pending";
                $card = Cards::findOne(['id' => $c_->card_id]);
                if ($card->card_skip == 1)
                    $u_c->card_status = "active";
                $u_c->save();
            }
        }
    }

    public static function insertFlow($user_id, $space_id) {

        $user = User::findOne(['id' => $user_id]);

        $user_role = SpaceUserRole::findOne( array('space_id' => $space_id,
            'user_id' => $user->id  ) );

        $workflow = StepFlow::currentWorkflow(Space::findOne($space_id));

        if (!$workflow) return;



        $group_user=GroupUser::find()->where(['user_id' => $user->id])->one();
        if($group_user){
            $group_id=$group_user->group_id;
        }else {
            $session = Yii::$app->session;
            $valueGroup = $session->get('linkedinUserType');

            if (!empty($valueGroup)) {
                $group_id = $valueGroup;
                $session->remove('linkedinUserType');

            } else {
                if (Yii::$app->request->post('User')
                    || (Invite::find()->where(['email' => $user->email])->exists())
                ) {
                    //venimos del registro

                    /* Search the user in the database */
                    $userInviteObject = Invite::findOne(['email' => $user->email]);

                    if (!empty($userInviteObject)) {
                        $existingUserInviteGroup =
                            UserInviteGroup::findOne(['user_invite_id' => $userInviteObject->id]);

                        if (!empty($existingUserInviteGroup)) {
                            $group_id = $existingUserInviteGroup->group_id;
                        } else {
                            $group_id = INNOVATOR_GROUP_ID;
                        }
                    } else {
                        $group_id = INNOVATOR_GROUP_ID; //siempre se le asigna el rol de innovator
                    }
                } else {
                    if (!$user->getGroups()->one()) {
                        return;
                    }
                    $group_id = $user->getGroups()->one()->id;
                }
            }
        }

        if (!$user_role) {

            $default_role = UserRoleWorkflow::findOne(array('default' => true, 'workflow_id' => $workflow->workflow_id,
                'group_id' =>  $group_id ));

            $current_space_role = new SpaceUserRole();
            $current_space_role->space_id = $space_id;
            $current_space_role->user_id = $user->id;
            $current_space_role->user_role_id = $default_role->user_role_id;
            $current_space_role->save();

            $steps = Step::findAll(array('workflow_id' => $workflow->workflow_id,
                'user_role_id' => $default_role->user_role_id));

        } else
            $steps = Step::findAll(array('workflow_id' => $workflow->workflow_id,
                'user_role_id' => $user_role->user_role_id));


        StepFlow::setStepUser((object) ['space_id' => $space_id, 'user_id' => $user_id], $steps);


        $card = Card::find()->leftJoin('cards',
            'card.card_id=cards.id')->where(array('card_type_id' => InviteController::CARD_TYPE,
            'space_id' => $space_id))->one();

        if ($card) {
            $user_card = UserCard::findOne(array('card_id' => $card->getPrimaryKey()));

            if ($user_card && $user_card->user_id != $user_id)
                StepFlow::updateFlowStatus($card->getPrimaryKey(), StepUserSpace::STATUS_COMPLETED, $user_card->user_id);
        }

    }

    public static function deleteFlow($user_id, $space_id) {


        $user_steps = StepUserSpace::findAll(array('user_id' => $user_id, 'space_id' => $space_id ));

        foreach ($user_steps as $user_step ) {
            $cards = Card::find()->leftJoin('cards',
                'card.card_id=cards.id')->where(array('card.space_id' => $space_id,
                'cards.step_id' => $user_step->step_id))->all();

            foreach ($cards as $c_) {

                $user_cards = UserCard::findAll(array('user_id' => $user_id, 'card_id' => $c_->id));

                foreach ($user_cards as $user_card) $user_card->delete();
            }

            $user_step->delete();
        }

    }
}
