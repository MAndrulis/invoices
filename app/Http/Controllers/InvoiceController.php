<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductInvoice;
use Illuminate\Http\Request;
use App\Http\Validators\InvoiceValidator;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\CountriesService as CS;

class InvoiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, CS $cs)
    {
        $invoices = Invoice::select('*');

        // date sort
        $invoices = match($request->sort) {
            'old' => $invoices->orderBy('invoice_date', 'asc'),
            'new' => $invoices->orderBy('invoice_date', 'desc'),
            default => $invoices,
        };

        // distict client ids
        $clients = Invoice::select('client_id')->distinct()->pluck('client_id')->toArray();

        // add client name to the client id
        $clients = array_map(function ($clientId) {
            return [$clientId, Client::find($clientId)->client_name];
        }, $clients);

        //sort by client name
        $clients = collect($clients)->sortBy(1)->toArray();

        // add "All clients" option
        array_unshift($clients, ['all', 'All clients']);

        // filter by client
        $invoices = match($request->client ?? 'all') {
            'all' => $invoices,
            default => $invoices->where('client_id', $request->client),
        };

        // filter by archive
        $invoices = match($request->archive ?? 'all') {
            'all' => $invoices,
            'archived' => $invoices->where('archive', '!=', null),
            'not_archived' => $invoices->where('archive', null),
        };




        $invoices = match($request->per_page) {
            'all' => $invoices->get(),
            '30' => $invoices->paginate(30)->withQueryString(),
            '50' => $invoices->paginate(50)->withQueryString(),
            default => $invoices->paginate(15)->withQueryString(),
        };


        return view('invoices.index', [
            'invoices' => $invoices,
            'countries' => $cs->getCountries(),
            'perPage' => $request->per_page ?? '15',
            'perPageOptions' => Invoice::RESULTS_PER_PAGE,
            'sortOptions' => Invoice::SORTS,
            'selectedSort' => $request->sort ?? '',
            'clientOptions' => $clients,
            'selectedClient' => $request->client ?? 'all',
            'archiveOptions' => Invoice::ARCHIVES,
            'selectedArchive' => $request->archive ?? 'all',
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // $prices = [];
        // Product::all()->pluck('price', 'id')->each(function ($item, $key) use (&$prices) {
        //     $prices[] = ['id' => $key, 'price' => $item];
        // })->toArray();

        
        return view('invoices.create', [
                'clients' => Client::all(),
                'products' => Product::all(),
            ]
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = (new InvoiceValidator())->validate($request);
 
        if ($validator->fails()) {
            return redirect()
            ->route('invoices-create')
            ->withErrors($validator)
            ->withInput();
        }

        $invoice = Invoice::create([
            'invoice_number' => $request->number,
            'invoice_date' => $request->date,
            'client_id' => $request->client_id,
        ]);

        foreach ($request->product_id as $key => $value) {
            $quantity = $request->quantity[$key];
            $inRow = $request->in_row[$key];
            ProductInvoice::create([
                'product_id' => $value,
                'invoice_id' => $invoice->id,
                'quantity' => $quantity,
                'in_row' => $inRow,
            ]);
        }

        return redirect()
            ->route('invoices-index')
            ->with('msg', ['type' => 'success', 'content' => 'Invoice was created successfully.']);
            // redirect to the index page with a success message
    }

    /**
     * Display the specified resource.
     */
    public function show(Invoice $invoice)
    {
        $number = $invoice->invoice_number;
        $data = (object) $invoice->archive;
        return view('invoices.show', [
            'number' => $number,
            'data' => $data,
            'id' => $invoice->id,
        ]);
    }

    public function download(Invoice $invoice)
    {
        $number = $invoice->invoice_number;
        $data = (object) $invoice->archive;
        $pdf = pdf::loadView('invoices.pdf', [
            'number' => $number,
            'data' => $data,
            'id' => $invoice->id,
        ]);
        return $pdf->download('invoice-'.$number.'.pdf');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Invoice $invoice)
    {
        
        if ($invoice->archive) {
            return redirect()
            ->route('invoices-index')
            ->with('msg', ['type' => 'danger', 'content' => 'Invoice is archived.']);
        }
        
        $products = collect(); // create a new collection

        $invoice->getPivot->each(function ($item, $key) use (&$products) {
            $product = (object)[]; // create a new object
            $product->id = $item->product_id;
            $product->quantity = $item->quantity;
            $product->price = $item->product->price;
            $product->name = $item->product->name;
            $product->product_id = $item->product_id;
            $product->total = number_format($item->quantity * $item->product->price, 2);
            $product->in_row = $item->in_row;
          
            $products->add($product); // add the object to the collection
        });
        
        return view('invoices.edit', [
            'invoice' => $invoice,
            'clients' => Client::all(),
            'invoiceLines' => $products->sortBy('in_row'),
            'products' => Product::all(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Invoice $invoice)
    {
        
        $validator = (new InvoiceValidator())->validate($request);
 
        if ($validator->fails()) {
            return redirect()
            ->route('invoices-edit', ['invoice' => $invoice])
            ->withErrors($validator)
            ->withInput();
        }

        if (isset($request->archive)) {
            // add products to archive

            $products = [];
            $total = 0;
            foreach ($request->in_row as $key => $row) {
                $product = [];
                $product['row'] = $row;
                $product['quantity'] = $request->quantity[$key];
                $product['price'] = Product::find($request->product_id[$key])->price;
                $product['name'] = Product::find($request->product_id[$key])->name;
                $product['total'] = number_format($product['quantity'] * $product['price'], 2);
                $products[] = $product;
                $total += $product['total'];
            }
     

            $invoice->update([
                'archive' => [
                    'invoice_date' => $request->date,
                    'products' => $products,
                    'total' => $total,
                    'client_name' => Client::find($request->client_id)->client_name,
                    'client_address' => Client::find($request->client_id)->client_address,
                    'client_address2' => Client::find($request->client_id)->client_address2,
                    'client_country' => Invoice::$countryList[Client::find($request->client_id)->client_country],
                ],
            ]);

            // delete pivot
            $invoice->getPivot->each(function ($item, $key) {
                $item->delete();
            });

            return redirect()
            ->route('invoices-index')
            ->with('msg', ['type' => 'success', 'content' => 'Invoice was archived successfully.']);
        }
        
        $invoice->update([
            'invoice_date' => $request->date,
            'client_id' => $request->client_id,
        ]);


        // Find what to delete
        $toDelete = $invoice->getPivot->filter(function ($item, $key) use ($request) {
            return !in_array($item->product_id, $request->product_id);
        });

        // Delete the items
        $toDelete->each(function ($item, $key) {
            $item->delete();
        });

        // Find what to add
        $toAdd = collect($request->product_id)->filter(function ($item, $key) use ($invoice) {
            return !$invoice->getPivot->contains('product_id', $item);
        });

        // Add the items
        $toAdd->each(function ($item, $key) use ($invoice, $request) {
            $quantity = $request->quantity[$key];
            ProductInvoice::create([
                'product_id' => $item,
                'invoice_id' => $invoice->id,
                'quantity' => $quantity,
                'in_row' => $request->in_row[$key],
            ]);
        });

        // Edit all the rest
        collect($request->product_id)->each(function ($item, $key) use ($invoice, $request) {
            $quantity = $request->quantity[$key];
            $invoice->getPivot->where('product_id', $item)->first()?->update([ // if item exists, update it
                'quantity' => $quantity,
                'product_id' => $item,
                'in_row' => $request->in_row[$key],
            ]);
        });


        // $invoice->getPivot->each(function ($item, $key) {
        //     $item->delete();
        // });

        // foreach ($request->product_id as $key => $value) {
        //     $quantity = $request->quantity[$key];
        //     ProductInvoice::create([
        //         'product_id' => $value,
        //         'invoice_id' => $invoice->id,
        //         'quantity' => $quantity,
        //     ]);
        // }

        return redirect()
            ->route('invoices-index')
            ->with('msg', ['type' => 'success', 'content' => 'Invoice was updated successfully.']);
            // redirect to the index page with a success message
    }

    /**
     * Show delete confirmation page.
     */
    public function delete(Invoice $invoice)
    {
        return view('invoices.delete', [
            'invoice' => $invoice,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Invoice $invoice)
    {
        $invoice->delete(); // delete the object from the database

        return redirect()
        ->route('invoices-index')
        ->with('msg', ['type' => 'info', 'content' => 'Invoice was deleted successfully.']);
        // redirect to the index page with a info message
    }

    public function showLine()
    {
        $html = view('invoices.line')
        ->with(['products' => Product::all()])
        ->render();

        return response()->json(['html' => $html]);
    }
}