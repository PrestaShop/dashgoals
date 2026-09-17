<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\DashGoals\Controller;

use Configuration;
use ConfigurationKPI;
use dashgoals;
use Module;
use PrestaShop\Module\DashGoals\Type\ConfigurationType;
// FrameworkBundleAdminController is deprecated since 9.0 in favor of PrestaShopAdminController,
// which doesn't exist on PS 8.2 — kept for the module's declared 8.2+ compatibility.
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Annotation\AdminSecurity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigurationController extends FrameworkBundleAdminController
{
    private const TYPES = ['traffic', 'conversion', 'avg_cart_value'];

    /**
     * @AdminSecurity("is_granted('read', request.get('_legacy_controller'))")
     */
    public function indexAction(Request $request): Response
    {
        /** @var dashgoals $module */
        $module = Module::getInstanceByName('dashgoals');
        $monthLabels = $module->getMonthLabels();

        $year = (int) $request->query->get('year', (int) Configuration::get('PS_DASHGOALS_CURRENT_YEAR'));

        $data = [];
        foreach (array_keys($monthLabels) as $month) {
            // PHP casts array keys like '10'..'12' (no leading zero) to int; '01'..'09' stay
            // strings — normalize back to string.
            $month = (string) $month;
            foreach (self::TYPES as $type) {
                // ConfigurationKPI::get() is documented as always returning string, but actually
                // returns false when the key doesn't exist yet for this year/month.
                $value = ConfigurationKPI::get($this->getConfigurationKey($type, $month, $year));
                /* @phpstan-ignore-next-line */
                $data[$type . '_' . $month] = $value === false ? 0 : $value;
            }
        }

        $form = $this->createForm(ConfigurationType::class, $data, ['month_labels' => $monthLabels]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->denyAccessUnlessGranted('update', $request->attributes->get('_legacy_controller'));

            foreach ($form->getData() as $field => $value) {
                [$type, $month] = $this->splitFieldName($field);
                ConfigurationKPI::updateValue($this->getConfigurationKey($type, $month, $year), $value);
            }
            // The dashboard widget always shows PS_DASHGOALS_CURRENT_YEAR (no year picker of its
            // own since #41971): saving goals for a year makes it the one shown there, mirroring
            // the legacy in-widget year selector this settings page replaces.
            Configuration::updateValue('PS_DASHGOALS_CURRENT_YEAR', $year);
            $this->addFlash('success', $this->trans('Successful update.', 'Admin.Notifications.Success'));

            return $this->redirectToRoute('dashgoals_configuration', ['year' => $year]);
        }

        return $this->render('@Modules/dashgoals/views/templates/admin/configuration.html.twig', [
            'configurationForm' => $form->createView(),
            'monthLabels' => $monthLabels,
            'year' => $year,
            'enableSidebar' => true,
            'help_link' => $this->generateSidebarLink('AdminDashgoalsConfiguration'),
        ]);
    }

    private function getConfigurationKey(string $type, string $month, int $year): string
    {
        return sprintf('DASHGOALS_%s_%s_%d', strtoupper($type), $month, $year);
    }

    /**
     * @return array{0: string, 1: string} [type, month] — reverses the 'type_MM' field name
     */
    private function splitFieldName(string $field): array
    {
        $month = substr($field, -2);
        $type = substr($field, 0, -3);

        return [$type, $month];
    }
}
