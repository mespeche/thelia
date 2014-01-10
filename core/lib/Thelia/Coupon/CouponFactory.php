<?php
/**********************************************************************************/
/*                                                                                */
/*      Thelia	                                                                  */
/*                                                                                */
/*      Copyright (c) OpenStudio                                                  */
/*      email : info@thelia.net                                                   */
/*      web : http://www.thelia.net                                               */
/*                                                                                */
/*      This program is free software; you can redistribute it and/or modify      */
/*      it under the terms of the GNU General Public License as published by      */
/*      the Free Software Foundation; either version 3 of the License             */
/*                                                                                */
/*      This program is distributed in the hope that it will be useful,           */
/*      but WITHOUT ANY WARRANTY; without even the implied warranty of            */
/*      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the             */
/*      GNU General Public License for more details.                              */
/*                                                                                */
/*      You should have received a copy of the GNU General Public License         */
/*	    along with this program. If not, see <http://www.gnu.org/licenses/>.      */
/*                                                                                */
/**********************************************************************************/

namespace Thelia\Coupon;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Thelia\Condition\ConditionFactory;
use Thelia\Coupon\Type\CouponInterface;
use Thelia\Exception\CouponExpiredException;
use Thelia\Exception\InvalidConditionException;
use Thelia\Model\Coupon;

/**
 * Generate a CouponInterface
 *
 * @package Coupon
 * @author  Guillaume MOREL <gmorel@openstudio.fr>
 *
 */
class CouponFactory
{
    /** @var ContainerInterface Service Container */
    protected $container = null;

    /** @var  FacadeInterface Provide necessary value from Thelia*/
    protected $facade;

    /**
     * Constructor
     *
     * @param ContainerInterface $container Service container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->facade = $container->get('thelia.facade');
    }

    /**
     * Build a CouponInterface from its database data
     *
     * @param string $couponCode Coupon code ex: XMAS
     *
     * @throws \Thelia\Exception\CouponExpiredException
     * @throws \Thelia\Exception\InvalidConditionException
     * @return CouponInterface                             ready to be processed
     */
    public function buildCouponFromCode($couponCode)
    {
        /** @var Coupon $couponModel */
        $couponModel = $this->facade->findOneCouponByCode($couponCode);
        if ($couponModel === null) {
           return false;
        }

        if ($couponModel->getExpirationDate() < new \DateTime()) {
            throw new CouponExpiredException($couponCode);
        }

        /** @var CouponInterface $couponInterface */
        $couponInterface = $this->buildCouponFromModel($couponModel);
        if ($couponInterface && $couponInterface->getConditions()->count() == 0) {
            throw new InvalidConditionException(
                get_class($couponInterface)
            );
        }

        return $couponInterface;
    }

    /**
     * Build a CouponInterface from its Model data contained in the DataBase
     *
     * @param Coupon $model Database data
     *
     * @return CouponInterface ready to use CouponInterface object instance
     */
    public function buildCouponFromModel(Coupon $model)
    {
        $isCumulative = ($model->getIsCumulative() == 1 ? true : false);
        $isRemovingPostage = ($model->getIsRemovingPostage() == 1 ? true : false);

        if (!$this->container->has($model->getType())) {
            return false;
        }

        /** @var CouponInterface $couponManager*/
        $couponManager = $this->container->get($model->getType());
        $couponManager->set(
            $this->facade,
            $model->getCode(),
            $model->getTitle(),
            $model->getShortDescription(),
            $model->getDescription(),
            $model->getEffects(),
            $isCumulative,
            $isRemovingPostage,
            $model->getIsAvailableOnSpecialOffers(),
            $model->getIsEnabled(),
            $model->getMaxUsage(),
            $model->getExpirationDate()
        );

        /** @var ConditionFactory $conditionFactory */
        $conditionFactory = $this->container->get('thelia.condition.factory');
        $conditions = $conditionFactory->unserializeConditionCollection(
            $model->getSerializedConditions()
        );

        $couponManager->setConditions($conditions);

        return clone $couponManager;
    }

}
