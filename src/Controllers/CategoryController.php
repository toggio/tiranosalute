<?php

// Controller del catalogo categorie visita usato
// nella ricerca disponibilità e nella prenotazione finale.

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CategoryRepository;

final class CategoryController
{
    public function __construct(private readonly CategoryRepository $categories)
    {
    }

    public function list(): array
    {
        return $this->categories->listAll();
    }
}
