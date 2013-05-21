<?php

/*
 * This file is part of Fork CMS.
 *
 * For the full copyright and license information, please view the license
 * file that was distributed with this source code.
 */

/**
 * This is the detail-action
 *
 * @author Tijs Verkoyen <tijs@sumocoders.be>
 * @author Davy Hellemans <davy.hellemans@netlash.com>
 * @author Dieter Vanden Eynde <dieter.vandeneynde@netlash.com>
 */
class CommonMailMessage
{
	public static function create($variables = array(), $template = null)
	{
		$message = self::createEmptyMessage();

		if (null === $template) {
			$template = FrontendModel::getContainer()->getParameter('site.path_www') .
				'/frontend/core/layout/templates/mails/notification.tpl';
		}

		$spoonTemplate = new FrontendTemplate(false);
		if(!empty($variables)) {
			$spoonTemplate->assign($variables);
		}
		$template = $spoonTemplate->display($template);

		$message->setBody($template);

		return $message;
	}

	public static function createEmptyMessage()
	{
		$swiftMessage = \Swift_Message::newInstance();

		$from = FrontendModel::getModuleSetting('core', 'mailer_from');
		$to = FrontendModel::getModuleSetting('core', 'mailer_to');

		$swiftMessage->setFrom($from['email']);
		$swiftMessage->setTo($to['email']);

		return $swiftMessage;
	}
}
