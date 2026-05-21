<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use JsonSerializable;
use Override;
use RuntimeException;

final readonly class State implements JsonSerializable
{
    private const array STATES = [
        'AU' => [
            'ACT' => 'AUSTRALIAN CAPITAL TERRITORY',
            'NSW' => 'NEW SOUTH WALES',
            'NT' => 'NORTHERN TERRITORY',
            'QLD' => 'QUEENSLAND',
            'SA' => 'SOUTH AUSTRALIA',
            'WA' => 'WESTERN AUSTRALIA',
            'TAS' => 'TASMANIA',
            'VIC' => 'VICTORIA',
        ],
        'CA' => [
            'AB' => 'ALBERTA',
            'BC' => 'BRITISH COLUMBIA',
            'MB' => 'MANITOBA',
            'NB' => 'NEW BRUNSWICK',
            'NF' => 'NEWFOUNDLAND',
            'NT' => 'NORTHWEST TERRITORIES',
            'NS' => 'NOVA SCOTIA',
            'ON' => 'ONTARIO',
            'PE' => 'PRINCE EDWARD ISLAND',
            'QC' => 'QUEBEC',
            'SK' => 'SASKATCHEWAN',
            'YT' => 'YUKON TERRITORY',
        ],
        'IN' => [
            'AP' => 'ANDHRA PRADESH',
            'AR' => 'ARUNACHAL PRADESH',
            'AS' => 'ASSAM',
            'BR' => 'BIHAR',
            'CG' => 'CHATTISGARH',
            'GA' => 'GOA',
            'GJ' => 'GUJARAT',
            'HR' => 'HARYANA',
            'HP' => 'HIMACHAL PRADESH',
            'JK' => 'JAMMU & KASHMIR',
            'JH' => 'JHARKHAND',
            'KA' => 'KARNATAKA',
            'KL' => 'KERALA',
            'MP' => 'MADHYA PRADESH',
            'MH' => 'MAHARASHTRA',
            'MN' => 'MANIPUR',
            'ML' => 'MEGHALAYA',
            'MZ' => 'MIZORAM',
            'NL' => 'NAGALAND',
            'OR' => 'ORISSA',
            'PB' => 'PUNJAB',
            'RJ' => 'RAJASTHAN',
            'SK' => 'SIKKIM',
            'TN' => 'TAMIL NADU',
            'TR' => 'TRIPURA',
            'UA' => 'UTTARAKHAND',
            'UP' => 'UTTAR PRADESH',
            'WB' => 'WEST BENGAL',
            'AN' => 'ANDAMAN & NICOBAR',
            'CH' => 'CHANDIGARH',
            'DN' => 'DADRA AND NAGAR HAVELI',
            'DD' => 'DAMAN & DIU',
            'DL' => 'DELHI',
            'LD' => 'LAKSHADWEEP',
            'PY' => 'PUDUCHERRY',
        ],
        'NZ' => [
            'AUK' => 'AUCKLAND',
            'BOP' => 'BAY OF PLENTY',
            'CAN' => 'CANTERBURY',
            'GIS' => 'GISBORNE',
            'HKB' => 'HAWKE\'S BAY',
            'MBH' => 'MARLBOROUGH',
            'MWT' => 'MANAWATU - WANGANUI',
            'NSN' => 'NELSON',
            'NTL' => 'NORTHLAND',
            'OTA' => 'OTAGO',
            'STL' => 'SOUTHLAND',
            'TAS' => 'TASMAN',
            'TKI' => 'TARANAKI',
            'WKO' => 'WAIKATO',
            'WGW' => 'WELLINGTON',
            'WTC' => 'WEST COAST',
            'CIT' => 'CHATHAM ISLANDS TERRITORY',
        ],
        'GB' => [
            'ALD' => 'ALDERNEY',
            'GSY' => 'GUERNSEY',
            'JSY' => 'JERSEY',
            'SRK' => 'SARK',
            'AVN' => 'AVON',
            'BDF' => 'BEDFORDSHIRE',
            'BRK' => 'BERKSHIRE',
            'BKM' => 'BUCKINGHAMSHIRE',
            'CAM' => 'CAMBRIDGESHIRE',
            'CHS' => 'CHESHIRE',
            'CLV' => 'CLEVELAND',
            'DUR' => 'CO. DURHAM',
            'CON' => 'CORNWALL',
            'CUL' => 'CUMBERLAND',
            'CMA' => 'CUMBRIA',
            'DBY' => 'DERBYSHIRE',
            'DEV' => 'DEVON',
            'DOR' => 'DORSET',
            'ERY' => 'EAST RIDING OF YORKSHIRE',
            'SXE' => 'EAST SUSSEX',
            'ESS' => 'ESSEX',
            'GLS' => 'GLOUCESTERSHIRE',
            'GTM' => 'GREATER MANCHESTER',
            'HAM' => 'HAMPSHIRE',
            'HWR' => 'HEREFORD AND WORCESTER',
            'HEF' => 'HEREFORDSHIRE',
            'HRT' => 'HERTFORDSHIRE',
            'HUM' => 'HUMBERSIDE',
            'HUN' => 'HUNTINGDONSHIRE',
            'IOW' => 'ISLE OF WIGHT',
            'KEN' => 'KENT',
            'LAN' => 'LANCASHIRE',
            'LEI' => 'LEICESTERSHIRE',
            'LIN' => 'LINCOLNSHIRE',
            'MSY' => 'MERSEYSIDE',
            'NFK' => 'NORFOLK',
            'NRY' => 'NORTH RIDING OF YORKSHIRE',
            'NYK' => 'NORTH YORKSHIRE',
            'NTH' => 'NORTHAMPTONSHIRE',
            'NBL' => 'NORTHUMBERLAND',
            'NTT' => 'NOTTINGHAMSHIRE',
            'OXF' => 'OXFORDSHIRE',
            'RUT' => 'RUTLAND',
            'SAL' => 'SHROPSHIRE',
            'SOM' => 'SOMERSET',
            'SYK' => 'SOUTH YORKSHIRE',
            'STS' => 'STAFFORDSHIRE',
            'SFK' => 'SUFFOLK',
            'SRY' => 'SURREY',
            'SSX' => 'SUSSEX',
            'TWR' => 'TYNE AND WEAR',
            'WAR' => 'WARWICKSHIRE',
            'WMD' => 'WEST MIDLANDS',
            'WRY' => 'WEST RIDING OF YORKSHIRE',
            'SXW' => 'WEST SUSSEX',
            'WYK' => 'WEST YORKSHIRE',
            'WES' => 'WESTMORLAND',
            'WIL' => 'WILTSHIRE',
            'WOR' => 'WORCESTERSHIRE',
            'YKS' => 'YORKSHIRE',
            'CAR' => 'CO. CARLOW',
            'CAV' => 'CO. CAVAN',
            'CLA' => 'CO. CLARE',
            'COR' => 'CO. CORK',
            'DON' => 'CO. DONEGAL',
            'DUB' => 'CO. DUBLIN',
            'GAL' => 'CO. GALWAY',
            'KER' => 'CO. KERRY',
            'KID' => 'CO. KILDARE',
            'KIK' => 'CO. KILKENNY',
            'LEX' => 'CO. LAOIS',
            'LET' => 'CO. LEITRIM',
            'LIM' => 'CO. LIMERICK',
            'LOG' => 'CO. LONGFORD',
            'LOU' => 'CO. LOUTH',
            'MAY' => 'CO. MAYO',
            'MEA' => 'CO. MEATH',
            'MOG' => 'CO. MONAGHAN',
            'OFF' => 'CO. OFFALY',
            'ROS' => 'CO. ROSCOMMON',
            'SLI' => 'CO. SLIGO',
            'TIP' => 'CO. TIPPERARY',
            'WAT' => 'CO. WATERFORD',
            'WEM' => 'CO. WESTMEATH',
            'WEX' => 'CO. WEXFORD',
            'WIC' => 'CO. WICKLOW',
            'ANT' => 'CO. ANTRIM',
            'ARM' => 'CO. ARMAGH',
            'DOW' => 'CO. DOWN',
            'FER' => 'CO. FERMANAGH',
            'LDY' => 'CO. LONDONDERRY',
            'TYR' => 'CO. TYRONE',
            'ABD' => 'ABERDEENSHIRE',
            'ANS' => 'ANGUS',
            'ARL' => 'ARGYLLSHIRE',
            'AYR' => 'AYRSHIRE',
            'BAN' => 'BANFFSHIRE',
            'BEW' => 'BERWICKSHIRE',
            'BOR' => 'BORDERS',
            'BUT' => 'BUTE',
            'CAI' => 'CAITHNESS',
            'CEN' => 'CENTRAL',
            'CLK' => 'CLACKMANNANSHIRE',
            'DGY' => 'DUMFRIES AND GALLOWAY',
            'DFS' => 'DUMFRIES-SHIRE',
            'DNB' => 'DUNBARTONSHIRE',
            'ELN' => 'EAST LOTHIAN',
            'FIF' => 'FIFE',
            'GMP' => 'GRAMPIAN',
            'HLD' => 'HIGHLAND',
            'INV' => 'INVERNESS-SHIRE',
            'KCD' => 'KINCARDINESHIRE',
            'KRS' => 'KINROSS-SHIRE',
            'KKD' => 'KIRKCUDBRIGHTSHIRE',
            'LKS' => 'LANARKSHIRE',
            'LTN' => 'LOTHIAN',
            'MLN' => 'MIDLOTHIAN',
            'MOR' => 'MORAYSHIRE',
            'NAI' => 'NAIRN',
            'OKI' => 'ORKNEY',
            'PEE' => 'PEEBLES-SHIRE',
            'PER' => 'PERTH',
            'RFW' => 'RENFREWSHIRE',
            'ROC' => 'ROSS AND CROMARTY',
            'ROX' => 'ROXBURGHSHIRE',
            'SEL' => 'SELKIRKSHIRE',
            'SHI' => 'SHETLAND',
            'STI' => 'STIRLINGSHIRE',
            'STD' => 'STRATHCLYDE',
            'SUT' => 'SUTHERLAND',
            'TAY' => 'TAYSIDE',
            'WLN' => 'WEST LOTHIAN',
            'WIS' => 'WESTERN ISLES',
            'WIG' => 'WIGTOWNSHIRE',
            'AGY' => 'ANGLESEY',
            'BRE' => 'BRECONSHIRE',
            'CAE' => 'CAERNARVONSHIRE',
            'CGN' => 'CARDIGANSHIRE',
            'CMN' => 'CARMARTHENSHIRE',
            'CWD' => 'CLWYD',
            'DEN' => 'DENBIGHSHIRE',
            'DFD' => 'DYFED',
            'FLN' => 'FLINTSHIRE',
            'GLA' => 'GLAMORGAN',
            'GNT' => 'GWENT',
            'GWN' => 'GWYNEDD',
            'MER' => 'MERIONETHSHIRE',
            'MGM' => 'MID GLAMORGAN',
            'MON' => 'MONMOUTHSHIRE',
            'MGY' => 'MONTGOMERYSHIRE',
            'PEM' => 'PEMBROKESHIRE',
            'POW' => 'POWYS',
            'RAD' => 'RADNORSHIRE',
            'SGM' => 'SOUTH GLAMORGAN',
            'WGM' => 'WEST GLAMORGAN',
        ],
        'US' => [
            'AL' => 'ALABAMA',
            'AK' => 'ALASKA',
            'AS' => 'AMERICAN SAMOA',
            'AZ' => 'ARIZONA',
            'AR' => 'ARKANSAS',
            'CA' => 'CALIFORNIA',
            'CO' => 'COLORADO',
            'CT' => 'CONNECTICUT',
            'DE' => 'DELAWARE',
            'DC' => 'DISTRICT OF COLUMBIA',
            'FM' => 'FEDERATED STATES OF MICRONESIA',
            'FL' => 'FLORIDA',
            'GA' => 'GEORGIA',
            'GU' => 'GUAM',
            'HI' => 'HAWAII',
            'ID' => 'IDAHO',
            'IL' => 'ILLINOIS',
            'IN' => 'INDIANA',
            'IA' => 'IOWA',
            'KS' => 'KANSAS',
            'KY' => 'KENTUCKY',
            'LA' => 'LOUISIANA',
            'ME' => 'MAINE',
            'MH' => 'MARSHALL ISLANDS',
            'MD' => 'MARYLAND',
            'MA' => 'MASSACHUSETTS',
            'MI' => 'MICHIGAN',
            'MN' => 'MINNESOTA',
            'MS' => 'MISSISSIPPI',
            'MO' => 'MISSOURI',
            'MT' => 'MONTANA',
            'NE' => 'NEBRASKA',
            'NV' => 'NEVADA',
            'NH' => 'NEW HAMPSHIRE',
            'NJ' => 'NEW JERSEY',
            'NM' => 'NEW MEXICO',
            'NY' => 'NEW YORK',
            'NC' => 'NORTH CAROLINA',
            'ND' => 'NORTH DAKOTA',
            'MP' => 'NORTHERN MARIANA ISLANDS',
            'OH' => 'OHIO',
            'OK' => 'OKLAHOMA',
            'OR' => 'OREGON',
            'PW' => 'PALAU',
            'PA' => 'PENNSYLVANIA',
            'PR' => 'PUERTO RICO',
            'RI' => 'RHODE ISLAND',
            'SC' => 'SOUTH CAROLINA',
            'SD' => 'SOUTH DAKOTA',
            'TN' => 'TENNESSEE',
            'TX' => 'TEXAS',
            'UT' => 'UTAH',
            'VT' => 'VERMONT',
            'VI' => 'VIRGIN ISLANDS',
            'VA' => 'VIRGINIA',
            'WA' => 'WASHINGTON',
            'WV' => 'WEST VIRGINIA',
            'WI' => 'WISCONSIN',
            'WY' => 'WYOMING',
        ],
    ];

    private string $name;

    private ?string $country;

    public function __construct(private string $state, ?Country $country = null)
    {
        $this->country = $country !== null ? (string) $country : null;

        if ($country === null) {
            $this->name = $state;

            return;
        }

        isset(self::STATES[(string) $country]) || throw new RuntimeException('Invalid state');

        $this->name = self::STATES[(string) $country][$this->state];
    }

    public static function all(?Country $country = null): array
    {
        if ($country === null) {
            $result = [];

            foreach (array_keys(self::STATES) as $country) {
                array_push($result, ...array_map(fn (string $state) => new State($state, new Country($country)), array_keys(self::STATES[$country])));
            }

            return $result;
        }

        if (! isset(self::STATES[(string) $country])) {
            return [];
        }

        return array_map(
            fn (string $code) => new State($code, $country),
            array_keys(self::STATES[(string) $country]),
        );
    }

    public function __toString(): string
    {
        return $this->state;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function getName(): string
    {
        return $this->name;
    }

    #[Override]
    public function jsonSerialize(): string
    {
        return (string) $this;
    }
}
