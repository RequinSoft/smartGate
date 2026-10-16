<?php

return [
    'required' => 'El campo :attribute es obligatorio.',
    'string' => 'El campo :attribute debe ser texto.',
    'integer' => 'El campo :attribute debe ser un entero.',
    'email' => 'El correo no es válido.',
    'unique' => 'El valor de :attribute ya está registrado.',
    'exists' => 'La selección de :attribute no existe.',
    'regex' => 'El formato de :attribute no es válido.',
    'url' => 'La URL de :attribute no es válida.',
    'array' => 'El campo :attribute debe ser una lista.',
    'distinct' => 'El campo :attribute tiene valores duplicados.',
    'date' => 'El campo :attribute debe ser una fecha válida.',
    'date_format' => 'La fecha :attribute debe tener el formato :format.',
    'after_or_equal' => 'La fecha :attribute debe ser igual o posterior a :date.',
    'in' => 'El valor de :attribute no es válido.',
    'present' => 'Falta el campo :attribute.',
    'min' => ['string' => 'El campo :attribute requiere al menos :min caracteres.', 'numeric' => 'El campo :attribute debe ser al menos :min.'],
    'max' => ['string' => 'El campo :attribute admite como máximo :max caracteres.', 'numeric' => 'El campo :attribute no puede superar :max.'],
    'attributes' => ['name' => 'nombre', 'number' => 'número', 'personId' => 'persona', 'vehicleId' => 'vehículo', 'deviceIds' => 'dispositivos', 'password' => 'contraseña', 'email' => 'correo', 'from' => 'desde', 'to' => 'hasta', 'baseUrl' => 'URL base'],
];
