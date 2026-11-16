<?php

namespace App\Account;

use App\Api\ApiDate;
use App\Entity\Address;
use App\Entity\User;

/**
 * Formes JSON du compte client (contrat annexe A.1 `User`, A.7 `Address`).
 * Les champs inconnus sont omis (jamais `null`), comme le veut le contrat.
 */
final class AccountNormalizer
{
    /** @return array<string, mixed> */
    public function user(User $user): array
    {
        $data = [
            'id'    => (string) $user->getId(),
            'phone' => $user->getPhone(),
        ];
        foreach (['firstName' => $user->getFirstName(), 'lastName' => $user->getLastName(), 'city' => $user->getCity()] as $key => $value) {
            if ($value !== null && $value !== '') {
                $data[$key] = $value;
            }
        }
        $data['createdAt'] = ApiDate::format($user->getCreatedAt());

        return $data;
    }

    /**
     * `quarter`, `details` et `phone` sont les doublons historiques de `district`, `landmark` et
     * `recipientPhone` (contrat §5.3) : renvoyés avec la même valeur.
     *
     * @return array<string, mixed>
     */
    public function address(Address $address): array
    {
        $data = [
            'id'            => $address->getId(),
            'label'         => $address->getLabel(),
            'city'          => $address->getCity(),
            'district'      => $address->getDistrict(),
            'quarter'       => $address->getDistrict(),
            'landmark'      => $address->getLandmark(),
            'details'       => $address->getLandmark(),
            'receptionMode' => $address->getReceptionMode(),
        ];
        if ($address->getAgencyId() !== null) {
            $data['agencyId'] = $address->getAgencyId();
        }
        if ($address->getRecipientName() !== null) {
            $data['recipientName'] = $address->getRecipientName();
        }
        if ($address->getRecipientPhone() !== null) {
            $data['recipientPhone'] = $address->getRecipientPhone();
            $data['phone'] = $address->getRecipientPhone();
        }
        $data['isDefault'] = $address->isDefault();

        return $data;
    }
}
