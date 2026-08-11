<?php

/*
|--------------------------------------------------------------------------
| Profile pick-lists
|--------------------------------------------------------------------------
|
| The closed sets the self-service profile offers. Everything here is both
| the source for the dropdowns the forms render and the whitelist the form
| requests validate against, so the two can never disagree.
|
| Values are stored as written. Adding an option is safe; renaming one
| orphans the records already carrying the old value.
|
*/

return [

    'titles' => [
        'Mr', 'Mrs', 'Miss', 'Ms', 'Master', 'Dr', 'Prof', 'Engr', 'Barr', 'Rev', 'Chief',
    ],

    'genders' => ['Male', 'Female'],

    'marital_statuses' => ['Single', 'Married', 'Divorced', 'Separated', 'Widowed'],

    'blood_groups' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],

    'genotypes' => ['AA', 'AS', 'AC', 'SS', 'SC', 'CC'],

    'religions' => ['Christianity', 'Islam', 'Traditional', 'Others', 'Prefer not to say'],

    'relationships' => [
        'Father', 'Mother', 'Spouse', 'Son', 'Daughter', 'Brother', 'Sister',
        'Uncle', 'Aunt', 'Cousin', 'Nephew', 'Niece', 'Grandparent', 'Guardian',
        'Friend', 'Other',
    ],

    'address_types' => ['Residential', 'Permanent', 'Postal', 'Other'],

    /*
    | States keyed by ISO country code. A country absent from this list gets a
    | free-text state field instead of a dropdown.
    */
    'states' => [
        'NG' => [
            'Abia State', 'Adamawa State', 'Akwa Ibom State', 'Anambra State',
            'Bauchi State', 'Bayelsa State', 'Benue State', 'Borno State',
            'Cross River State', 'Delta State', 'Ebonyi State', 'Edo State',
            'Ekiti State', 'Enugu State', 'Federal Capital Territory',
            'Gombe State', 'Imo State', 'Jigawa State', 'Kaduna State',
            'Kano State', 'Katsina State', 'Kebbi State', 'Kogi State',
            'Kwara State', 'Lagos State', 'Nasarawa State', 'Niger State',
            'Ogun State', 'Ondo State', 'Osun State', 'Oyo State',
            'Plateau State', 'Rivers State', 'Sokoto State', 'Taraba State',
            'Yobe State', 'Zamfara State',
        ],
    ],

    'banks' => [
        'Access Bank',
        'Citibank Nigeria',
        'Ecobank Nigeria',
        'Fidelity Bank',
        'First Bank of Nigeria',
        'First City Monument Bank',
        'Globus Bank',
        'Guaranty Trust Bank',
        'Heritage Bank',
        'Jaiz Bank',
        'Keystone Bank',
        'Kuda Microfinance Bank',
        'Lotus Bank',
        'Moniepoint Microfinance Bank',
        'Opay Digital Services',
        'Optimus Bank',
        'PalmPay',
        'Parallex Bank',
        'Polaris Bank',
        'PremiumTrust Bank',
        'Providus Bank',
        'Signature Bank',
        'Stanbic IBTC Bank',
        'Standard Chartered Bank',
        'Sterling Bank',
        'SunTrust Bank',
        'Titan Trust Bank',
        'Union Bank of Nigeria',
        'United Bank for Africa',
        'Unity Bank',
        'Wema Bank',
        'Zenith Bank',
        'Other',
    ],

    'pension_administrators' => [
        'ARM Pension Managers',
        'Access Pensions',
        'CrusaderSterling Pensions',
        'Fidelity Pension Managers',
        'FCMB Pensions',
        'Guaranty Trust Pension Managers',
        'Leadway Pensure PFA',
        'NLPC Pension Fund Administrators',
        'NPF Pensions',
        'Nigerian University Pension Management Company',
        'Norrenberger Pensions',
        'Oak Pensions',
        'Premium Pension',
        'Radix Pension Managers',
        'Stanbic IBTC Pension Managers',
        'Tangerine APT Pensions',
        'Trustfund Pensions',
        'Veritas Glanvills Pensions',
        'Other',
    ],

];
