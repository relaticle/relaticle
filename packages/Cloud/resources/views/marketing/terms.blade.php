<x-cloud::guest-layout 
    :title="'Terms of Service - ' . config('app.name')"
    description="Relaticle Terms of Service - Use of the Relaticle website and all related services is subject to the following terms of service."
    :ogTitle="'Terms of Service - ' . config('app.name')"
    ogDescription="Read the Terms of Service for Relaticle. Learn about the rules and guidelines for using our open-source CRM platform.">
    <x-cloud::legal-document
        title="Terms of Service"
        subtitle="Use of the Relaticle website and all related services is subject to the following terms of service."
        :content="$terms"
    />
</x-cloud::guest-layout>
