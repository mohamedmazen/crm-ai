<?php

namespace App\Modules\Integrations\Services\Credentials;

class MetaCredentials extends CredentialValueObject
{
    public function appId(): ?string
    {
        return $this->get('app_id');
    }

    public function appSecret(): ?string
    {
        return $this->get('app_secret');
    }

    public function systemUserToken(): ?string
    {
        return $this->get('system_user_token');
    }

    public function verifyToken(): ?string
    {
        return $this->get('verify_token');
    }

    /**
     * Verify token Meta must be given for the app-global WhatsApp webhook
     * callback URL (/webhooks/whatsapp/global). Derived from the App ID and
     * App Secret so it never has to be stored or typed by hand.
     *
     * Changing the App Secret changes this value — re-register the webhook.
     */
    public function globalWebhookVerifyToken(): ?string
    {
        if (! $this->appId() || ! $this->appSecret()) {
            return null;
        }

        return hash('sha256', $this->appId().$this->appSecret().'wh_global_verify');
    }

    public function configIdWhatsapp(): ?string
    {
        return $this->get('config_id_whatsapp') ?: null;
    }

    public function configIdSocial(): ?string
    {
        return $this->get('config_id_social') ?: null;
    }
}
