export type Option = { value: string | number; label: string };

export type CountryOption = Option & { value: string; dial: string };

export type RelationKind = 'next_of_kin' | 'dependant' | 'family_member';

export type RelationKindOption = {
    value: RelationKind;
    label: string;
    plural: string;
};

export type ProfileOptions = {
    titles: Option[];
    genders: Option[];
    marital_statuses: Option[];
    blood_groups: Option[];
    genotypes: Option[];
    religions: Option[];
    relationships: Option[];
    address_types: Option[];
    banks: Option[];
    pension_administrators: Option[];
    relation_kinds: RelationKindOption[];
    countries: CountryOption[];
    // Keyed by ISO country code. A country absent from here has no state list,
    // so the form falls back to a free-text field.
    states: Record<string, Option[]>;
};

export type EmployeeProfile = {
    employee_id: string | null;
    attendance_id: string | null;
    // On the user record rather than the profile, but edited on the same tab.
    hired_at: string | null;
    first_name: string | null;
    last_name: string | null;
    other_names: string | null;
    title: string | null;
    gender: string | null;
    date_of_birth: string | null;
    place_of_birth: string | null;
    marital_status: string | null;
    mothers_maiden_name: string | null;
    spouse_name: string | null;
    spouse_phone: string | null;
    number_of_kids: number | null;
    blood_group: string | null;
    genotype: string | null;
    religion: string | null;
    allergies: string | null;
    medical_history: string | null;
    national_id_number: string | null;
    country_of_origin: string | null;
    state_of_origin: string | null;
    local_government: string | null;
    phone: string | null;
    alternate_phone: string | null;
    email: string;
    alternate_email: string | null;
    avatar_url: string | null;
    initials: string;

    bank_name: string | null;
    account_number: string | null;
    account_name: string | null;
    bvn: string | null;
    swift_code: string | null;
    sort_code: string | null;
    annual_rent: string | null;
    rsa_number: string | null;
    pfa_name: string | null;
    tax_identification_number: string | null;
    nhf_number: string | null;
};

export type EmployeeRelation = {
    id: number;
    kind: RelationKind;
    name: string;
    relationship: string;
    phone: string | null;
    email: string | null;
    date_of_birth: string | null;
    gender: string | null;
    occupation: string | null;
    address: string | null;
};

export type EmployeeAddress = {
    id: number;
    label: string;
    street: string;
    city: string | null;
    state: string | null;
    country: string | null;
    postal_code: string | null;
    one_line: string;
};

export type ProfileTab = 'profile' | 'family' | 'bank';
