# VendLink Diagrams

All system diagrams are maintained in this file. The section identifiers are used by `scripts/generate_diagrams.py` to create the previews. The detailed model follows the persisted entities in `schema.sql`; usernames shown with marketplace products and orders are joined from `User`, not stored as product or order columns. SVG exports stay sharp at any size in Word; PNG exports are rendered at high resolution with 300 DPI metadata.

## Class Diagrams

### simplified-class-diagram

```mermaid
classDiagram
    direction TB

    class User {
        +String id
        +String name
        +String email
        +UserRole role
    }

    class Admin {
        +manageUsers()
        +viewDeliveries(search, status)
        +updateSettings()
    }

    class Vendor {
        +searchSuppliers(name, username)
        +filterOrdersByDate(year, month, day)
        +sortOrders(dateTime, supplier)
        +placeOrder()
        +exportVendorReport()
    }

    class Supplier {
        +manageProducts()
        +fulfillOrders()
        +viewAnalytics()
    }

    class Product {
        +String name
        +Float price
        +Int stockQuantity
        +String category
        +ProductStatus status
    }

    class Order {
        +String orderNumber
        +Float totalAmount
        +OrderStatus status
        +String deliveryAddress
    }

    class OrderItem {
        +Int quantity
        +Float priceAtOrder
    }

    class DeliveryAddress {
        +String recipientName
        +String phone
        +String address
        +Boolean isDefault
    }

    class Notification {
        +String title
        +String message
        +Boolean isRead
    }

    User <|-- Admin
    User <|-- Vendor
    User <|-- Supplier

    Supplier "1" --> "0..*" Product : catalogs & stocks
    Vendor "1" --> "0..*" Order : places
    Supplier "1" --> "0..*" Order : receives & fulfills
    Order "1" *-- "1..*" OrderItem : contains
    Product "1" <-- "0..*" OrderItem : references
    User "1" --> "0..*" Notification : receives
    Vendor "1" --> "0..*" DeliveryAddress : saves
```

### detailed-class-diagram

```mermaid
classDiagram
    direction TB

    class UserRole {
        <<enumeration>>
        ADMIN
        VENDOR
        SUPPLIER
    }

    class ProductStatus {
        <<enumeration>>
        PENDING
        APPROVED
        DENIED
    }

    class OrderStatus {
        <<enumeration>>
        PENDING
        CONFIRMED
        DELIVERED
        CANCELLED
    }

    class DemandStatus {
        <<enumeration>>
        LOW
        MEDIUM
        HIGH
    }

    class ApprovalStatus {
        <<enumeration>>
        PENDING
        APPROVED
        DISAPPROVED
    }

    class User {
        #String id
        #String email
        #String password
        #String name
        #String businessName
        #String username
        #String contactNumber
        #UserRole role
        #ApprovalStatus approvalStatus
        #Boolean isActive
        #Boolean isApproved
        #Boolean twoFactorEnabled
        #Boolean notificationsEnabled
        #DateTime createdAt
        #DateTime updatedAt
    }

    class Admin {
        +manageUsers()
        +viewDeliveries(search, status)
        +updateSettings()
    }

    class Vendor {
        +searchSuppliers(name, contact, username)
        +filterAndSortOrders(date, supplier)
        +exportVendorReport()
    }

    class Supplier {
        +manageProducts()
        +fulfillOrders()
        +viewAnalytics()
    }

    class Product {
        +Int id
        +String supplierId
        +String name
        +Float price
        +Int stockQuantity
        +String category
        +ProductStatus status
        +Boolean hasVariants
        +DateTime createdAt
    }

    class ProductVariant {
        +Int id
        +Int productId
        +String name
        +String sku
        +Float price
        +Int stockQuantity
    }

    class Order {
        +Int id
        +String vendorId
        +String vendorName
        +String vendorBusinessName
        +String supplierId
        +String supplierName
        +String supplierBusinessName
        +Int supplierOrderNumber
        +Float totalAmount
        +OrderStatus status
        +String paymentMethod
        +String deliveryAddress
        +DateTime createdAt
    }

    class OrderItem {
        +Int id
        +Int orderId
        +Int productId
        +Int variantId
        +String name
        +Float priceAtOrder
        +Int quantity
    }

    class DeliveryAddress {
        +Int id
        +String vendorId
        +String recipientName
        +String phone
        +String addressLine1
        +String city
        +String postalCode
        +Boolean isDefault
    }

    class MarketplaceProductView {
        <<API projection>>
        +String supplierBusinessName
        +String supplierContactName
        +String supplierUsername
    }

    class OrderView {
        <<API projection>>
        +String vendorUsername
        +String supplierUsername
    }

    class Notification {
        +Int id
        +String userId
        +String title
        +String type
        +Boolean isRead
        +DateTime createdAt
    }

    class SecurityLog {
        +Int id
        +String userId
        +String ip
        +String action
        +DateTime createdAt
    }

    class Setting {
        +String key
        +String value
        +DateTime updatedAt
    }

    class UserDevice {
        +Int id
        +String userId
        +String deviceTokenHash
        +DateTime lastSeenAt
    }

    class LoginVerification {
        +Int id
        +String userId
        +String purpose
        +DateTime expiresAt
        +Boolean isVerified
    }

    User <|-- Admin
    User <|-- Vendor
    User <|-- Supplier

    Supplier "1" --> "0..*" Product : owns & manages
    Product "1" *-- "0..*" ProductVariant : offers
    Vendor "1" --> "0..*" Order : creates
    Supplier "1" --> "0..*" Order : receives
    Order "1" *-- "1..*" OrderItem : composed of
    Product "1" <-- "0..*" OrderItem : snapshots
    ProductVariant "0..1" <-- "0..*" OrderItem : selected variant
    Vendor "1" --> "0..*" DeliveryAddress : saves
    User "1" --> "0..*" Notification : receives
    User "1" --> "0..*" SecurityLog : generates
    User "1" --> "0..*" UserDevice : trusts
    User "1" --> "0..*" LoginVerification : verifies
    Admin "1" ..> Setting : manages
    Product ..> MarketplaceProductView : joins supplier details
    Order ..> OrderView : joins account usernames
```

`Admin`, `Vendor`, and `Supplier` are role-specific views of rows in `users` (selected by `users.role`), not separate database tables. `MarketplaceProductView` and `OrderView` are API projections; their username and supplier account fields are joined from `users`.

## Activity Diagrams

### supplier-activity-diagram

```mermaid
%%{init: {'theme': 'base', 'themeVariables': { 'fontFamily': 'Arial', 'fontSize': '18px', 'primaryTextColor': '#000000', 'primaryBorderColor': '#000000', 'lineColor': '#000000', 'tertiaryColor': '#ffffff', 'background': '#ffffff', 'mainBkg': '#ffffff', 'clusterBkg': '#ffffff', 'clusterBorder': '#000000'}}}%%
flowchart TD
    classDef action fill:#fff,stroke:#000,stroke-width:2.5px,color:#000
    classDef decision fill:#fff,stroke:#000,stroke-width:2.5px,color:#000
    classDef start fill:#000,stroke:#000,color:#fff,stroke-width:2.5px
    classDef terminal fill:#000,stroke:#000,color:#fff,stroke-width:2.5px
    linkStyle default stroke:#000,stroke-width:2.5px

    start(( )) --> account{Have an account?}
    account -->|Yes| login[Log in as supplier]
    login --> credentials[Enter email and password]
    credentials --> authenticated{Authenticated?}
    authenticated -->|No| login
    authenticated -->|Yes| dashboard[View supplier dashboard]
    account -->|No| register[Register supplier account]
    register --> details[Enter business name, full name, username, email, address, contact number, and password]
    details --> submit[Submit supplier account request]
    submit --> pending[Wait for admin review and approval notice]
    pending --> finish(( ))

    dashboard --> inventory[View product catalog]
    inventory --> edit{Add, edit, or delete a product?}
    edit -->|Add or edit| productDetails[Enter product details and variants]
    productDetails --> valid{Details valid?}
    valid -->|No| productDetails
    valid -->|Yes| save[Save product and refresh inventory]
    edit -->|Delete| remove[Delete selected product]

    dashboard --> orders[Review incoming vendor orders]
    orders --> status{Update an order?}
    status -->|Yes| update[Confirm, deliver, or cancel the order]
    update --> notify[Notify vendor and refresh order list]

    dashboard --> analytics[Review sales, product demand, and top buyers]
    dashboard --> profile[Update profile, business details, and password]
    dashboard --> logout[Log out]
    save --> logout
    remove --> logout
    status -->|No| logout
    notify --> logout
    analytics --> logout
    profile --> logout
    logout --> finish

    class start start
    class finish terminal
    class account,authenticated,edit,valid,status decision
    class login,credentials,dashboard,inventory,productDetails,save,remove,orders,update,notify,analytics,profile,logout,register,details,submit,pending action
```

### vendor-activity-diagram

```mermaid
%%{init: {'theme': 'base', 'themeVariables': { 'fontFamily': 'Arial', 'fontSize': '18px', 'primaryTextColor': '#000000', 'primaryBorderColor': '#000000', 'lineColor': '#000000', 'tertiaryColor': '#ffffff', 'background': '#ffffff', 'mainBkg': '#ffffff', 'clusterBkg': '#ffffff', 'clusterBorder': '#000000'}}}%%
flowchart TD
    classDef action fill:#fff,stroke:#000,stroke-width:2.5px,color:#000
    classDef decision fill:#fff,stroke:#000,stroke-width:2.5px,color:#000
    classDef start fill:#000,stroke:#000,color:#fff,stroke-width:2.5px
    classDef terminal fill:#000,stroke:#000,color:#fff,stroke-width:2.5px
    linkStyle default stroke:#000,stroke-width:2.5px

    start(( )) --> account{Have an account?}
    account -->|Yes| login[Log in as vendor]
    login --> credentials[Enter email and password]
    credentials --> authenticated{Authenticated?}
    authenticated -->|No| login
    authenticated -->|Yes| dashboard[View vendor dashboard]
    account -->|No| register[Register vendor account]
    register --> details[Enter business name, full name, username, email, address, contact number, and password]
    details --> submit[Submit vendor account request]
    submit --> pending[Wait for admin review and approval notice]
    pending --> finish(( ))

    dashboard --> hub[Open Vendor Hub]
    hub --> export[Export procurement report to Excel]
    hub --> browse[Browse approved marketplace products]
    browse --> filter[Search supplier business name, contact name, or username; filter and sort supplies]
    filter --> select[Select a product and quantity]
    select --> stock{Product or variant in stock?}
    stock -->|No| browse
    stock -->|Yes| cart[Add item to cart and view cart]
    cart --> checkout[Enter delivery address and notes]
    checkout --> valid{Order details valid?}
    valid -->|No| checkout
    valid -->|Yes| placeOrder[Place cash-on-delivery order]
    placeOrder --> created{Order created?}
    created -->|No| browse
    created -->|Yes| received[Supplier receives pending order]
    received --> logout

    hub --> orders[Open My Orders]
    orders --> filterOrders[Search supplier name or username; filter by year, month, and day]
    filterOrders --> sortOrders[Sort by order date and time or supplier name or username]
    sortOrders --> track[Track order status and delivery]
    track --> delivered{Delivered?}
    delivered -->|No| cancel{Cancel eligible order?}
    cancel -->|Yes| cancelOrder[Cancel order]
    cancelOrder --> logout
    cancel -->|No| track
    delivered -->|Yes| logout

    dashboard --> insights[Review spending, purchase history, and suppliers]
    dashboard --> profile[Update profile details and save changes]
    dashboard --> logout[Log out]
    filter --> logout
    insights --> logout
    profile --> logout
    logout --> finish

    class start start
    class finish terminal
    class account,authenticated,stock,valid,created,delivered,cancel decision
    class login,credentials,dashboard,hub,export,browse,filter,select,cart,checkout,placeOrder,received,orders,filterOrders,sortOrders,track,cancelOrder,insights,profile,logout,register,details,submit,pending action
```

### admin-activity-diagram

```mermaid
%%{init: {'theme': 'base', 'themeVariables': { 'fontFamily': 'Arial', 'fontSize': '18px', 'primaryTextColor': '#000000', 'primaryBorderColor': '#000000', 'lineColor': '#000000', 'tertiaryColor': '#ffffff', 'background': '#ffffff', 'mainBkg': '#ffffff', 'clusterBkg': '#ffffff', 'clusterBorder': '#000000'}}}%%
flowchart TD
    classDef action fill:#fff,stroke:#000,stroke-width:2.5px,color:#000
    classDef decision fill:#fff,stroke:#000,stroke-width:2.5px,color:#000
    classDef start fill:#000,stroke:#000,color:#fff,stroke-width:2.5px
    classDef terminal fill:#000,stroke:#000,color:#fff,stroke-width:2.5px
    linkStyle default stroke:#000,stroke-width:2.5px

    start(( )) --> account{Have an admin account?}
    account -->|Yes| login[Log in as admin]
    login --> credentials[Enter email and password]
    credentials --> authenticated{Authenticated?}
    authenticated -->|No| login
    authenticated -->|Yes| dashboard[View administration dashboard]
    account -->|No| provision[Request an admin account from the system owner]
    provision --> finish(( ))

    dashboard --> accounts[Search or filter vendor and supplier accounts]
    accounts --> review[Review account application]
    review --> decision{Choose account action}
    decision -->|Approve| approve[Approve account and send email]
    decision -->|Disapprove| disapprove[Disapprove account and send email]
    decision -->|Delete| delete[Delete account]
    approve --> refresh[Refresh account list and metrics]
    disapprove --> refresh
    delete --> refresh

    dashboard --> create[Enter supplier name and email]
    create --> createAccount[Create approved account and send setup link]
    createAccount --> refresh

    dashboard --> settings[View verification and admin email settings]
    settings --> change[Update verification requirement or admin email]
    change --> save[Save security settings]
    save --> refresh

    dashboard --> profile[Update admin profile and security settings]
    dashboard --> deliveries[View deliveries]
    deliveries --> deliveryFilters[Search receipt, vendor, supplier, or address; filter by status]
    deliveryFilters --> deliveryResults[Review delivery parties, address, status, and order time]
    dashboard --> notifications[Review and mark notifications as read]
    dashboard --> logout[Log out]
    profile --> logout
    notifications --> logout
    deliveryResults --> logout
    refresh --> logout
    logout --> finish

    class start start
    class finish terminal
    class account,authenticated,decision decision
    class login,credentials,dashboard,accounts,review,approve,disapprove,delete,refresh,create,createAccount,settings,change,save,profile,deliveries,deliveryFilters,deliveryResults,notifications,logout,provision action
```
