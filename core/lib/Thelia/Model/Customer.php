<?php

namespace Thelia\Model;

use Propel\Runtime\Exception\PropelException;
use Thelia\Model\AddressQuery;
use Thelia\Model\Base\Customer as BaseCustomer;

use Thelia\Model\Exception\InvalidArgumentException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use Thelia\Core\Event\CustomRefEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\User\UserInterface;

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Thelia\Model\Map\CustomerTableMap;
use Thelia\Core\Security\Role\Role;
use Thelia\Core\Event\Customer\CustomerEvent;

/**
 * Skeleton subclass for representing a row from the 'customer' table.
 *
 *
 *
 * You should add additional methods to this class to meet the
 * application requirements.  This class will only be generated as
 * long as it does not already exist in the output directory.
 *
 * @package    propel.generator.Thelia.Model
 */
class Customer extends BaseCustomer implements UserInterface
{
    use \Thelia\Model\Tools\ModelEventDispatcherTrait;

    /**
     * @param int $titleId customer title id (from customer_title table)
     * @param string $firstname customer first name
     * @param string $lastname customer last name
     * @param string $address1 customer address
     * @param string $address2 customer adress complement 1
     * @param string $address3 customer adress complement 2
     * @param string $phone customer phone number
     * @param string $cellphone customer cellphone number
     * @param string $zipcode customer zipcode
     * @param string $city
     * @param int $countryId customer country id (from Country table)
     * @param string $email customer email, must be unique
     * @param string $plainPassword customer plain password, hash is made calling setPassword method. Not mandatory parameter but an exception is thrown if customer is new without password
     * @param string $lang
     * @param int $reseller
     * @param null $sponsor
     * @param int $discount
     * @throws \Exception|\Symfony\Component\Config\Definition\Exception\Exception
     */
    public function createOrUpdate($titleId, $firstname, $lastname, $address1, $address2, $address3, $phone, $cellphone, $zipcode, $city, $countryId, $email = null, $plainPassword = null, $lang = null, $reseller = 0, $sponsor = null, $discount = 0, $company = null)
    {
        $this
        	->setTitleId($titleId)
            ->setFirstname($firstname)
            ->setLastname($lastname)
            ->setEmail($email)
            ->setPassword($plainPassword)
            ->setReseller($reseller)
            ->setSponsor($sponsor)
            ->setDiscount($discount)
        ;

        if(!is_null($lang)) {
            $this->setLang($lang);
        }


        $con = Propel::getWriteConnection(CustomerTableMap::DATABASE_NAME);
        $con->beginTransaction();
        try {
            if ($this->isNew()) {
                $address = new Address();

                $address
                    ->setLabel("default")
                    ->setCompany($company)
                    ->setTitleId($titleId)
                    ->setFirstname($firstname)
                    ->setLastname($lastname)
                    ->setAddress1($address1)
                    ->setAddress2($address2)
                    ->setAddress3($address3)
                    ->setPhone($phone)
                    ->setCellphone($cellphone)
                    ->setZipcode($zipcode)
                    ->setCity($city)
                    ->setCountryId($countryId)
                    ->setIsDefault(1)
                    ;

                $this->addAddress($address);

            } else {
                $address = $this->getDefaultAddress();

                $address
                    ->setCompany($company)
                    ->setTitleId($titleId)
                    ->setFirstname($firstname)
                    ->setLastname($lastname)
                    ->setAddress1($address1)
                    ->setAddress2($address2)
                    ->setAddress3($address3)
                    ->setPhone($phone)
                    ->setCellphone($cellphone)
                    ->setZipcode($zipcode)
                    ->setCity($city)
                    ->setCountryId($countryId)
                    ->save($con)
                ;
            }
            $this->save($con);

            $con->commit();

        } catch(PropelException $e) {
            $con->rollback();
            throw $e;
        }
    }

    protected function generateRef()
    {
        return uniqid(substr($this->getLastname(), 0, (strlen($this->getLastname()) >= 3) ? 3 : strlen($this->getLastname())), true);
    }

    /**
     * @return Address
     */
    public function getDefaultAddress()
    {
        return AddressQuery::create()
            ->filterByCustomer($this)
            ->filterByIsDefault(1)
            ->findOne();
    }

    /**
     * create hash for plain password and set it in Customer object
     *
     * @param string $password plain password before hashing
     * @throws Exception\InvalidArgumentException
     * @return $this|Customer
     */
    public function setPassword($password)
    {
        if ($this->isNew() && ($password === null || trim($password) == "")) {
            throw new InvalidArgumentException("customer password is mandatory on creation");
        }

        if($password !== null && trim($password) != "") {
            $this->setAlgo("PASSWORD_BCRYPT");
            return parent::setPassword(password_hash($password, PASSWORD_BCRYPT));
        }
        return $this;
    }

    public function setEmail($email, $force = false)
    {
        $email = trim($email);

        if ($this->isNew() && ($email === null || $email == "")) {
            throw new InvalidArgumentException("customer email is mandatory on creation");
        }

        if (!$this->isNew() && $force === false) {
            return $this;
        }

        return parent::setEmail($email);
    }

   /**
     * {@inheritDoc}
     */
    public function getUsername() {
    	return $this->getEmail();
    }

    /**
     * {@inheritDoc}
     */
    public function checkPassword($password)
    {
    	return password_verify($password, $this->password);
    }

    /**
     * {@inheritDoc}
     */
    public function eraseCredentials() {
    	$this->setPassword(null);
    }

    /**
     * {@inheritDoc}
     */
    public function getRoles() {
    	return array(new Role('CUSTOMER'));
    }

    /**
     * {@inheritDoc}
     */
    public function getToken() {
        return $this->getRememberMeToken();
    }

    /**
     * {@inheritDoc}
     */
    public function setToken($token) {
        $this->setRememberMeToken($token)->save();
    }

    /**
     * {@inheritDoc}
     */
    public function getSerial() {
        return $this->getRememberMeSerial();
    }

    /**
     * {@inheritDoc}
     */
    public function setSerial($serial) {
        $this->setRememberMeSerial($serial)->save();
    }

    /**
     * {@inheritDoc}
     */
    public function preInsert(ConnectionInterface $con = null)
    {
        // Set the serial number (for auto-login)
        $this->setRememberMeSerial(uniqid());

        $this->setRef($this->generateRef());

        $this->dispatchEvent(TheliaEvents::BEFORE_CREATECUSTOMER, new CustomerEvent($this));
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function postInsert(ConnectionInterface $con = null)
    {
        $this->dispatchEvent(TheliaEvents::AFTER_CREATECUSTOMER, new CustomerEvent($this));
    }

    /**
     * {@inheritDoc}
     */
    public function preUpdate(ConnectionInterface $con = null)
    {
        $this->dispatchEvent(TheliaEvents::BEFORE_UPDATECUSTOMER, new CustomerEvent($this));
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function postUpdate(ConnectionInterface $con = null)
    {
        $this->dispatchEvent(TheliaEvents::AFTER_UPDATECUSTOMER, new CustomerEvent($this));
    }

    /**
     * {@inheritDoc}
     */
    public function preDelete(ConnectionInterface $con = null)
    {
        $this->dispatchEvent(TheliaEvents::BEFORE_DELETECUSTOMER, new CustomerEvent($this));
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function postDelete(ConnectionInterface $con = null)
    {
        $this->dispatchEvent(TheliaEvents::AFTER_DELETECUSTOMER, new CustomerEvent($this));
    }
}
