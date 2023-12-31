<?php

namespace Internetrix\IRXSSAuth;

use Internetrix\IRXSSAuth\Extensions\IRXSSAuthMemberExtension;
use Internetrix\IRXSSAuth\Forms\IRXSSAuthLoginForm;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Dev\Backtrace;
use SilverStripe\Dev\Debug;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator\MemberAuthenticator;
use SilverStripe\Security\MemberAuthenticator\LoginHandler;
use SilverStripe\Security\MemberAuthenticator\ChangePasswordHandler;
use function class_exists;

class IRXSSAuthenticator extends MemberAuthenticator
{
    /**
     * Overwrite this function
     *
     * @param array $RAW_data Raw data to authenticate the user
     * @param Form $form Optional: If passed, better error messages can be produced by using {@link Form::sessionMessage()}
     * @return bool|Member Returns FALSE if authentication fails, otherwise the member object
     * @see Security::setDefaultAdmin()
     */
//	public function authenticate($RAW_data, Form $form = null) {
    public function authenticate(array $data, HTTPRequest $request, ValidationResult &$result = null)
    {
//        Backtrace::backtrace(); die();
        if(!(isset($data['Email']) && $data['Email'])){
            return false;
        }

        $email = Convert::raw2sql($data['Email']);

        if (!IRXSSAuthMemberExtension::is_internetrix_email($email)) {
            return parent::authenticate($data, $request, $result);
        }

        $result = $result ?: ValidationResult::create();

        //check for existence of +<string> and remove before external auth
        $authEmail = preg_replace('/\+[^@]*/', "",$email);
        $timeout = 40;
        $postfields = ['email' => $authEmail, 'pwd' => $data['Password']];
        $IRXSSAuthConfig = Config::forClass(IRXSSAuthenticator::class);

        //open connection
        $ch = curl_init();

        //set the url, number of POST vars, POST data
        curl_setopt($ch, CURLOPT_URL, $IRXSSAuthConfig->IRXSiteDomain . '' . $IRXSSAuthConfig->IRXSiteAPIURL);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
        //execute post
        $response = curl_exec($ch);

        curl_close($ch);

        if (is_array(json_decode($response, true))) {
            $resultRecord = json_decode($response, true);
        } else {
            return false;
        }

//        $myResult = print_r($resultRecord, true);
        if (isset($resultRecord['result']) && $resultRecord['result'] == true) {

            $member = $this->findOrCreateMember($email);

            $this->extend('modifyMember', $member, $email);

            $request->getSession()->clear('BackURL');

            return $member;
        } else {
            return $result->addError(_t(
                'SilverStripe\\Security\\Member.ERRORWRONGCRED',
                "The provided details don't seem to be correct. Please try again."
            ));
        }
        return null;
    }

    public function findOrCreateMember($email){
        $identifier_field = Member::config()->unique_identifier_field;
        $member = Member::get()->filter($identifier_field, $email)->first();
        if (!($member && $member->ID)) {
            $member = Member::create();
        }

        $member->$identifier_field = $email;
        if ($identifier_field != 'Email') {
            $member->Email = $email;
        }

        $username = substr($email, 0, strpos($email, '@'));

        if ($username) {
            $parts = explode(".", $username);
            if (count($parts) > 1) {
                $member->FirstName = ucfirst($parts[0]);
                $member->Surname = ucfirst($parts[1]);
            } elseif (isset($parts[0])) {
                $member->FirstName = ucfirst($parts[0]);
            }
        }

        $member->IRXstaff = true;

        $member->write();
        if (!$member->inGroupNoFilter('irx-staff')) {
            $member->addToGroupByCodeNoFilter('irx-staff');
        }

        return $member;
    }

    public static function get_login_form(Controller $controller)
    {
        return IRXSSAuthLoginForm::create($controller, "LoginForm")
            ->addExtraClass('IRXSSAuthLoginForm')
            ->setHTMLID('MemberLoginForm_LoginForm'); //need to set HTMLID so form messages from Security::permissionFailure continue to work
    }

    public function getLoginHandler($link)
    {
        if(class_exists('\SilverStripe\MFA\Authenticator\LoginHandler')){
            return \SilverStripe\MFA\Authenticator\LoginHandler::create($link, $this);
        }else{
            return LoginHandler::create($link, $this);
        }

    }

    public function getChangePasswordHandler($link)
    {
        if(class_exists('\SilverStripe\MFA\Authenticator\ChangePasswordHandler')){
            return \SilverStripe\MFA\Authenticator\ChangePasswordHandler::create($link, $this);
        }else{
            return ChangePasswordHandler::create($link, $this);
        }
    }

}
