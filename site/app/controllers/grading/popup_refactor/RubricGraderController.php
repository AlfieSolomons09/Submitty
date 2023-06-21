<?php

/**
 * ---------------------
 *
 * RubricGraderController.php
 *
 * This class's createMainRubricGradeablePage will eventually be called when a
 * Rubric Gradeable's grading page is opened.
 *
 * Currently, to access the page associated with this class, enter URL:
 *
 *     /courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading_beta[?more_stuff_here_is_okay]
 *
 * This class is also responsible for updating popup windows created.
 *
 * ---------------------
 */

// Namespace:
namespace app\controllers\grading\popup_refactor;

// Includes:
use app\controllers\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use app\libraries\GradeableType;
use app\models\gradeable\Gradeable;
use app\models\gradeable\GradedGradeable;
use app\models\User;

// Main Class:
class RubricGraderController extends AbstractController {
    // ---------------------------------

    // Member Variables:

    /**
     * @var Gradeable
     * The current gradeable being graded.
     */
    private $gradeable;

    /**
     * @var GradedGradeable
     * The current submission being graded.
     */
    private $current_submission;

    /**
     * @var string
     * The anonomous id of the student currently being grade.
     * This id can be set with setCurrentStudentId or when loading this page's URL
     * with ?who_id=INSERT_ID.
     */
    private $current_student_id;

    /**
     * @var string
     * By what ordering are we sorting by.
     * Controls where next and prev arrows go.
     */
    private $sort_type;

    /**
     * @var string
     * For a given ordering, do we sort it ascending "ASC" or descending "DSC".
     * Controls where next and prev arrows go.
     */
    private $sort_direction;

    /**
     * @var bool
     * Do we skip students that we are not assigned to when pressing next or prev arrows?
     */
    private $navigate_assigned_students_only;

   /**
    * @var int
    * User type for current website user.
    * Enum from User:
    *  1: User::GROUP_INSTRUCTOR
    *  2: User::GROUP_FULL_ACCESS_GRADER
    *  3: User::GROUP_LIMITED_ACCESS_GRADER
    *  4: User::GROUP_STUDENT
    */
    private $user_type;

   /**
    * @var bool
    * True if the current gradeable has peer grading.
    */
    private $is_peer_gradeable;

    /**
    * @var bool
    * True if the current gradeable has teams.
    */
    private $is_team_gradeable;

    /**
     * @var string
     *
     * The access mode for the current user for this gradeable. 
     * Possible Values:
     *  - "unblind" - Nothing about students is hidden.
     *  - "single"  - For peer grading or for full access grading's Anonymous Mode. Graders cannot see
     *               who they are currently grading.
     *  - "double"  - For peer grading. In addition to blinded peer graders, students cannot
     *               see which peer they are currently grading.
     */
    private $blind_access_mode = "";


    // ---------------------------------


    // ---


    // ---------------------------------

    // Functions:

    /**
     * Creates the Rubric Grading page.
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading_beta/grade")
     *
     * @param string $gradeable_id - The id string of the current gradeable.
     * @param string $who_id - The id of the student we should grade.
     * @param string $sort - The current way we are sorting students. Determines who the next and prev students are.
     * @param string $direction - Either "ASC" or "DESC" for ascending or descending sorting order.
     * @param string $navigate_assigned_students_only - When going to the next student, this variable controls
     *                whether we skip students.
     *
     * This page is loaded on line 476 of Details.twig when the Grade button is clicked.
     *
     * Note that the argument names cannot be changed easily as they need to line up with the arguments
     * provided to the URL.
     *
     */
    public function createMainRubricGraderPage(
        $gradeable_id,
        $who_id = '',
        $sort = "id",
        $direction = "ASC",
        $navigate_assigned_students_only = "true"
    ) {
        $this->setMemberVariables($gradeable_id, $who_id, $sort, $direction, $navigate_assigned_students_only);

        $this->core->getOutput()->renderOutput(
            // Path:
            ['grading', 'popup_refactor', 'RubricGrader'],
            // Function Name:
            'createRubricGradeableView',
            // Arguments:
            $this->gradeable,
            $this->current_submission,
            $this->sort_type,
            $this->sort_direction,
            $this->is_peer_gradeable,
            $this->is_team_gradeable,
            $this->blind_access_mode,
            $this->gradeableDetailsPage()
        );
    }


    /**
     * Sets the corresponding memeber variables based on provided arguments.
     *
     * @param string $gradeable_id - The id string of the current gradeable.
     * @param string $who_id - The id of the student we should grade.
     * @param string $sort - The current way we are sorting students. Determines who the next and prev students are.
     * @param string $direction - Either "ASC" or "DESC" for ascending or descending sorting order.
     * @param string $navigate_assigned_students_only - When going to the next student, this variable controls
     *     whether we skip students.
     */
    private function setMemberVariables($gradeable_id, $who_id, $sort, $direction, $navigate_assigned_students_only) {
        $this->setCurrentGradeable($gradeable_id);
        $this->setCurrentSubmission($who_id);
        $this->setUserType();

        $this->setIfPeerGradeable();
        $this->setIfTeamGradeable();
        $this->setBlindAccessMode();

        $this->current_student_id = $who_id;
        $this->sort_type = $sort;
        $this->sort_direction = $direction;
        $this->navigate_assigned_students_only;
    }


    /**
     * Sets $gradeable to the appropiate assignment unless $gradeable_id is invalid,
     * in which case an error is printed and the code exits.
     *
     * @param string $gradeable_id - The id string of the current gradeable.
     */
    private function setCurrentGradeable($gradeable_id) {
        // tryGetGradeable inherited from AbstractController
        $this->gradeable = $this->tryGetGradeable($gradeable_id, false);

        // Gradeable must exist and be Rubric.
        $error_message = "";
        if ($this->gradeable === false) {
            $error_message = 'Invalid Gradeable!';
        }
        if (empty($error_message) && $this->gradeable->getType() !== GradeableType::ELECTRONIC_FILE) {
            $error_message = 'This gradeable is not a rubric gradeable.';
        }

        if (!empty($error_message)) {
            $this->core->addErrorMessage($error_message);
            // The following line exits execution.
            $this->core->redirect($this->core->buildCourseUrl());
        }
    }


    /**
     * Sets the current student's submission we are looking at
     * If the submission does not exist, we exit the page.
     * 
     * @param string $who_id - The id of the student we should grade.
     */
    private function setCurrentSubmission($who_id) {
        $this->current_submission = $this->tryGetGradedGradeable($this->gradeable, $who_id, false);

        // Submission does not exist
        if ($this->current_submission === false) {
            $this->core->redirect($this->gradeableDetailsPage());
        }
    }

    /**
     * Returns the URL of this gradeable's details page.
     */
    private function gradeableDetailsPage() {
        return $this->core->buildCourseUrl(
            ['gradeable', $this->gradeable->getId(), 'grading', 'details']) 
            . '?'
            . http_build_query(['sort' => $this->sort_type, 'direction' => $this->sort_direction]
        );
    }


    /**
     * Sets the current user type for the website user.
     */
    private function setUserType() {
        $user_type = $this->core->getUser()->getGroup();
    }


    /**
     * Sets $is_peer_gradeable based on whether the current gradeable has peer grading.
     * Make sure setCurrentGradeable($gradeable_id) is called first to set the gradeable.
     */
    private function setIfPeerGradeable() {
        $this->is_peer_gradeable = $this->gradeable->hasPeerComponent();
    }


    /**
     * Sets $is_peer_gradeable based on whether the current gradeable has teams.
     * Make sure setCurrentGradeable($gradeable_id) is called first to set the gradeable.
     */
    private function setIfTeamGradeable() {
        $is_team_gradeable = $this->gradeable->isTeamAssignment();
    }


    /**
     * Sets $blind_access_mode for the current user for this grader session of this gradeable.
     *
     * Possible Values:
     *  - "unblind" - Nothing about students is hidden.
     *  - "single"  - For peer grading or for full access grading's Anonymous Mode. Graders cannot see
     *                who they are currently grading.
     *  - "double"  - For peer grading. In addition to blinded peer graders, students cannot
     *                see which peer they are currently grading.
     */
    private function setBlindAccessMode() {
        // Blind Settings for Instructors and Full Access Graders:
        if ($this->user_type === User::GROUP_INSTRUCTOR || $this->user_type === User::GROUP_FULL_ACCESS_GRADER) {
            if (isset($_COOKIE['anon_mode']) && $_COOKIE['anon_mode'] === 'on') {
                $this->blind_access_mode = "single";
            }
            else {
                $this->blind_access_mode = "unblind";
            }
        }

        // Blind Settings for Limited Access Graders:
        if ($this->user_type === User::GROUP_LIMITED_ACCESS_GRADER) {
            if ($this->gradeable->getLimitedAccessBlind() === Gradeable::SINGLE_BLIND_GRADING) {
                $this->blind_access_mode = "single";
            }
            else {
                $this->blind_access_mode = "unblind";
            }
        }

        // Blind Settings for Student Peer Graders:
        if ($this->user_type == User::GROUP_STUDENT) {
            if ($this->is_peer_gradeable) {
                if ($this->gradeable->getPeerBlind() === Gradeable::DOUBLE_BLIND_GRADING) {
                    $blind_access_mode = "double";
                }
                elseif ($this->gradeable->getPeerBlind() === Gradeable::SINGLE_BLIND_GRADING) {
                    $blind_access_mode = "single";
                }
                else {
                    $blind_access_mode = "unblind";
                }
            }

            else {
                $blind_access_mode = "unblind";
            }
        }
    }
}
