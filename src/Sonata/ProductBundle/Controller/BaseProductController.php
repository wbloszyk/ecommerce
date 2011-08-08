<?php

/*
 * This file is part of the Sonata package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\ProductBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Sonata\Component\Basket\BasketElement;
use Symfony\Component\Form\FormView;
use Sonata\Component\Order\OrderElementInterface;

use Application\Sonata\PaymentBundle\Entity\Transaction;

abstract class BaseProductController extends Controller
{
    public function viewAction($product)
    {
        if (!is_object($product)) {
            throw new NotFoundHttpException('invalid product instance');
        }

        $form     = $this->get('session')->getFlash('sonata.product.form');
        $provider = $this->get('sonata.product.pool')->getProvider($product);

        if (!$form) {
            $formBuilder = $this->get('form.factory')->createNamedBuilder('form', 'add_basket');
            $provider->defineAddBasketForm($product, $formBuilder);

            $form = $formBuilder->getForm()->createView();
        }

        return $this->render(sprintf('%s:view.html.twig', 'SonataProductBundle:Amazon' /*$provider->getBaseControllerName()*/), array(
           'product' => $product,
           'form'    => $form,
        ));
    }

    public function renderFormBasketElementAction(FormView $formView, BasketElement $basketElement)
    {
        $provider = $this->get('sonata.product.pool')->getProvider($basketElement->getProduct());

        return $this->render(sprintf('%s:form_basket_element.html.twig', $provider->getBaseControllerName()), array(
            'formView'    => $formView,
            'basketElement' => $basketElement,
        ));
    }

    public function renderFinalReviewBasketElementAction(BasketElement $basketElement)
    {
        $provider = $this->get('sonata.product.pool')->getProvider($basketElement->getProduct());

        return $this->render(sprintf('%s:final_review_basket_element.html.twig', $provider->getBaseControllerName()), array(
            'basketElement' => $basketElement,
        ));
    }

    public function viewVariationsAction($productId, $slug)
    {

    }

    public function viewBasketElement(BasketElement $basketElement)
    {

    }

    public function viewBasketElementConfirmation(BasketElement $basketElement)
    {

    }

    public function viewOrderElement(OrderElementInterface $orderElement)
    {

    }

    public function viewEditOrderElement(OrderElementInterface $orderElement)
    {

    }
}