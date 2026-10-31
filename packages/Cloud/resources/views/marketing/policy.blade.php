<x-cloud::guest-layout 
    :title="'Privacy Policy - ' . config('app.name')"
    description="Relaticle Privacy Policy - How we collect, use, and protect your personal information."
    :ogTitle="'Privacy Policy - ' . config('app.name')"
    ogDescription="Read the Privacy Policy for Relaticle. Learn about how we handle your personal information and protect your privacy.">
    <x-cloud::legal-document
        title="Privacy Policy"
        subtitle="How we collect, use, and protect your personal information."
        :content="$policy"
    />
</x-cloud::guest-layout>
