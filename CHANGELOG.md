# Changelog - LinkedDataSets Module

## [0.3.0] - 2025-10-03

### Major Changes
- **Breaking**: Migrated core infrastructure from `Generic` to `LinkedDataSets\Core` namespace to avoid conflicts with Daniel Berthereau's Generic module
- **Breaking**: Moved all core classes from `src/Generic/` to `src/Core/` directory:
  - `Generic\AbstractModule` → `LinkedDataSets\Core\AbstractModule`
  - `Generic\InstallResources` → `LinkedDataSets\Core\InstallResources` 
  - `Generic\ResourceTemplateMergeHelper` → `LinkedDataSets\Core\ResourceTemplateMergeHelper`
  - `Generic\CustomVocabMergeHelper` → `LinkedDataSets\Core\CustomVocabMergeHelper`

### Added
- Proper PSR-4 autoloader configuration in `Module.php` for the new `LinkedDataSets\Core` namespace
- Early class loading via `require_once` statements to ensure availability during module initialization
- Enhanced UriHelper service resolution with fallback instantiation mechanism
- Additional entity parsing in `CatalogDumpService` for contact points (`schema:contactPoint`)

### Fixed
- **Critical**: Resolved "Unable to resolve service LDS\UriHelper" error during data dump operations
- **Critical**: Fixed namespace conflicts that caused HTTP 500 errors during module installation
- **Performance**: Memory management improvements in `InstallResources.php` (removed excessive debug logging)
- **UX**: Preserved logical property ordering in resource templates by disabling alphabetical sorting
- Enhanced error handling in `DataDumpJob` with better fallback mechanisms
- Improved service container error handling in `UriHelperFactory`

### Changed
- Updated module version from `0.2` to `0.3` in `module.ini`
- Updated module configuration to properly register Core namespace services
- Enhanced `CatalogDumpService` to parse additional linked data entities (funders, contact points)
- Improved code formatting and consistency throughout the codebase
- Updated dependencies array structure in module configuration

### Technical Details
- All import statements updated to use `LinkedDataSets\Core` namespace
- Service factory enhanced with improved error handling and fallback mechanisms
- Resource template processing maintains original property order for better UX
- Module loading process now includes explicit class loading before autoloader availability
- Enhanced compatibility with Omeka S service container architecture

### Developer Notes
- Modules extending this functionality should update imports from `Generic\*` to `LinkedDataSets\Core\*`
- No changes to public API or user-facing functionality
- Backward compatibility maintained for existing data and configurations
- Installation process now stable and repeatable without namespace conflicts

### Files Changed
```
Modified:
- .gitignore (IDE configuration files)
- Module.php (namespace migration, autoloader config)
- composer.lock (dependency updates)
- config/module.config.php (service registration)
- config/module.ini (version update)
- src/Application/Job/DataDumpJob.php (UriHelper fallback)
- src/Application/Service/CatalogDumpService.php (additional entities)
- src/Infrastructure/Services/Factories/UriHelperFactory.php (error handling)

Moved/Renamed:
- src/Generic/ → src/Core/ (entire directory with namespace update)

Added:
- data/resource-templates/LDS_Contactpoint.json (new resource template)
```

### Migration Guide
For developers working with this module:
1. Update any custom code referencing `Generic\*` classes to `LinkedDataSets\Core\*`
2. Ensure module dependencies are properly configured if extending functionality
3. Test module installation in clean environment to verify compatibility
4. Update any import statements in custom extensions

---

## Previous Versions
See git history for versions prior to 0.3.0