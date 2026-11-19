<x-cloud::guest-layout 
    :title="__('Privacy Policy - :name', ['name' => config('app.name')])"
    :description="__('Relaticle Privacy Policy - How we collect, use, and protect your personal information.')"
    :ogTitle="__('Privacy Policy - :name', ['name' => config('app.name')])"
    :ogDescription="__('Read the Privacy Policy for Relaticle. Learn about how we handle your personal information and protect your privacy.')">
    <x-cloud::legal-document
        :title="__('Privacy Policy')"
        :subtitle="__('How we collect, use, and protect your personal information.')"
        :content="$policy"
    />
</x-cloud::guest-layout>
