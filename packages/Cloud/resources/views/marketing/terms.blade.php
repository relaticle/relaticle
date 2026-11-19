<x-cloud::guest-layout 
    :title="__('Terms of Service - :name', ['name' => config('app.name')])"
    :description="__('Relaticle Terms of Service - Use of the Relaticle website and all related services is subject to the following terms of service.')"
    :ogTitle="__('Terms of Service - :name', ['name' => config('app.name')])"
    :ogDescription="__('Read the Terms of Service for Relaticle. Learn about the rules and guidelines for using our open-source CRM platform.')">
    <x-cloud::legal-document
        :title="__('Terms of Service')"
        :subtitle="__('Use of the Relaticle website and all related services is subject to the following terms of service.')"
        :content="$terms"
    />
</x-cloud::guest-layout>
